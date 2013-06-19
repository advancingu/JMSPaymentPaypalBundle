<?php

namespace JMS\Payment\PaypalBundle\Plugin;

use JMS\Payment\CoreBundle\Model\ExtendedDataInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Util\Number;
use JMS\Payment\PaypalBundle\Client\AdaptivePaymentsClient as Client;
use JMS\Payment\PaypalBundle\Client\Response\ResponseInterface as Response;

/*
 * Copyright 2013 Markus Weiland <mweiland@graph-ix.net>
 * Copyright 2010 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/** Makes PayPal's Adaptive Payments API accessible via JMSPaymentCoreBundle.
 * @see https://developer.paypal.com/webapps/developer/docs/classic/adaptive-payments/integration-guide/APIntro/
 */ 
class AdaptivePaymentsPlugin extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $returnUrl;

    /**
     * @var string
     */
    protected $cancelUrl;

    /**
     * @var \JMS\Payment\PaypalBundle\Client\AdaptivePaymentsClient
     */
    protected $client;

    /**
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param \JMS\Payment\PaypalBundle\Client\AdaptivePaymentsClient $client
     */
    public function __construct($returnUrl, $cancelUrl, Client $client)
    {
        $this->client = $client;
        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
    }

//     public function approve(FinancialTransactionInterface $transaction, $retry)
//     {
//     }

    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        $checkoutParams = $data->get('checkout_params');
        
        // check if this is the second call, after payer comes back from PayPal; the money should now already be deposited
        // @see Explicit Approval Payment Flow at https://developer.paypal.com/webapps/developer/docs/classic/adaptive-payments/integration-guide/APIntro/
        if ($transaction->getState() === FinancialTransactionInterface::STATE_PENDING)
        {
            if ($this->isTransactionCompleted($transaction))
            {
                $amount = $checkoutParams['receiverList.receiver(0).amount'];
                $transaction->setProcessedAmount($amount);
                $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
                $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
            }

            return;
        }

        $parameters = array();
        $parameters['actionType'] = 'PAY'; // we'd actually prefer CREATE, however it doesn't work as documented
        $parameters['cancelUrl'] = $this->getCancelUrl($data);
        $parameters['returnUrl'] = $this->getReturnUrl($data);
        
        $parameters['requestEnvelope.errorLanguage'] = $checkoutParams['requestEnvelope.errorLanguage'];
        $parameters['currencyCode'] = $transaction->getPayment()->getPaymentInstruction()->getCurrency();
        $parameters['receiverList.receiver(0).email'] = $checkoutParams['receiverList.receiver(0).email'];
        $parameters['receiverList.receiver(0).amount'] = $checkoutParams['receiverList.receiver(0).amount'];
        
        // check for secondary receivers
        // @see Chained Payments at https://developer.paypal.com/webapps/developer/docs/classic/api/adaptive-payments/Pay_API_Operation/
        $hasPrimary = false;
        for ($i = 1; $i < 6; $i++) {
            if (array_key_exists('receiverList.receiver('.$i.').email', $checkoutParams)
                && array_key_exists('receiverList.receiver('.$i.').amount', $checkoutParams)) {
                
                $parameters['receiverList.receiver('.$i.').email'] = $checkoutParams['receiverList.receiver('.$i.').email'];
                $parameters['receiverList.receiver('.$i.').amount'] = $checkoutParams['receiverList.receiver('.$i.').amount'];
                
                if (!$hasPrimary
                    && array_key_exists('receiverList.receiver('.$i.').primary', $checkoutParams) 
                    && $checkoutParams['receiverList.receiver('.$i.').primary'] === 'true') {
                    $parameters['receiverList.receiver('.$i.').primary'] = 'true';
                    $hasPrimary = true;
                }
            }
        }
        if (!$hasPrimary) {
            $parameters['receiverList.receiver(0).primary'] = 'true';
        }
        if (array_key_exists('feesPayer', $checkoutParams)) {
            $parameters['feesPayer'] = $checkoutParams['feesPayer'];
        }
        if (array_key_exists('trackingId', $checkoutParams)) {
            $parameters['trackingId'] = $checkoutParams['trackingId'];
        }
        if (array_key_exists('memo', $checkoutParams)) {
            $parameters['memo'] = $checkoutParams['memo'];
        }
        
        $tokenResponse = $this->client->requestPay($parameters);
        
        $this->throwUnlessSuccessResponse($tokenResponse, $transaction);
        
        switch ($tokenResponse->body->get('paymentExecStatus')) {
            case 'ERROR':
                $ex = new FinancialException(sprintf('Pay action failed with "%s".', $tokenResponse->body->get('payErrorList')));
                $transaction->setResponseCode($tokenResponse->body->get('paymentExecStatus'));
                $transaction->setReasonCode('PaymentActionFailed');
                $ex->setFinancialTransaction($transaction);
        
                throw $ex;
        
            case 'CREATED':
                break;

            default:
                $ex = new FinancialException(sprintf('Handling of response "%s" not implemented.', $tokenResponse->body->get('paymentExecStatus')));
                $transaction->setResponseCode($tokenResponse->body->get('paymentExecStatus'));
                $transaction->setReasonCode('PaymentActionFailed');
                $ex->setFinancialTransaction($transaction);
        
                throw $ex;
        }

        $payKey = $tokenResponse->body->get('payKey');
        $data->set('payKey', $payKey);
        
        // now apply any payment options
        $paymentParams = $data->get('payment_params');
        $parameters = array();
        
        if (array_key_exists('displayOptions.businessName', $paymentParams)) {
            $parameters['displayOptions.businessName'] = $paymentParams['displayOptions.businessName'];
        }
        if (array_key_exists('receiverOptions.description', $paymentParams)) {
            $parameters['receiverOptions.description'] = $paymentParams['receiverOptions.description'];
        }
        if (array_key_exists('receiverOptions.customId', $paymentParams)) {
            $parameters['receiverOptions.customId'] = $paymentParams['receiverOptions.customId'];
        }
        if (array_key_exists('receiverOptions.invoiceData.totalTax', $paymentParams)) {
            $parameters['receiverOptions.invoiceData.totalTax'] = $paymentParams['receiverOptions.invoiceData.totalTax'];
        }
        if (array_key_exists('receiverOptions.invoiceData.totalShipping', $paymentParams)) {
            $parameters['receiverOptions.invoiceData.totalShipping'] = $paymentParams['receiverOptions.invoiceData.totalShipping'];
        }
        
        $i = 0;
        while (count(preg_grep("/^receiverOptions.invoiceData.item\(".$i."\)/", array_keys($paymentParams))) > 0) {
            $item = 'receiverOptions.invoiceData.item('.$i.')';
            if (array_key_exists($item . '.name', $paymentParams)) {
                $parameters[$item . '.name'] = $paymentParams[$item . '.name'];
            }
            if (array_key_exists($item . '.itemPrice', $paymentParams)) {
                $parameters[$item . '.itemPrice'] = $paymentParams[$item . '.itemPrice'];
            }
            if (array_key_exists($item . '.itemCount', $paymentParams)) {
                $parameters[$item . '.itemCount'] = $paymentParams[$item . '.itemCount'];
            }
            if (array_key_exists($item . '.identifier', $paymentParams)) {
                $parameters[$item . '.identifier'] = $paymentParams[$item . '.identifier'];
            }
            $i++;
        }
        
        $parameters['receiverOptions.receiver(0).email'] = $checkoutParams['receiverList.receiver(0).email'];
        
        if (count($parameters) > 0) {
            $parameters['payKey'] = $payKey;
            $parameters['requestEnvelope.errorLanguage'] = $checkoutParams['requestEnvelope.errorLanguage'];
            
            $optionsResponse = $this->client->requestSetPaymentOptions($parameters);
            $this->throwUnlessSuccessResponse($optionsResponse, $transaction);
        }
                
        // return to user for payKey authorization
        $actionRequest = new ActionRequiredException('User has not yet authorized the transaction.');
        $actionRequest->setFinancialTransaction($transaction);
        $actionRequest->setAction(new VisitUrl($this->client->getTokenAuthorizationUrl($payKey)));
        
        throw $actionRequest;
    }

//     public function credit(FinancialTransactionInterface $transaction, $retry)
//     {
//     }

//     public function deposit(FinancialTransactionInterface $transaction, $retry)
//     {
//     }

//     public function reverseApproval(FinancialTransactionInterface $transaction, $retry)
//     {
//     }

    public function processes($paymentSystemName)
    {
        return 'paypal_adaptive_payments' === $paymentSystemName;
    }

    public function isIndependentCreditSupported()
    {
        return false;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return boolean True, if PayPal returns "COMPLETED" status for $transaction
     */
    protected function isTransactionCompleted(FinancialTransactionInterface $transaction)
    {
        $data = $transaction->getExtendedData();
        $checkoutParams = $data->get('checkout_params');
        
        $parameters = array();
        $parameters['payKey'] = $data->get('payKey');
        $parameters['requestEnvelope.errorLanguage'] = $checkoutParams['requestEnvelope.errorLanguage'];
        $detailsResponse = $this->client->requestPaymentDetails($parameters);
        
        $this->throwUnlessSuccessResponse($detailsResponse, $transaction);
        
        return ($detailsResponse->body->get('status') === 'COMPLETED');
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param \JMS\Payment\PaypalBundle\Client\Response\ResponseInterface $response
     * @return null
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    protected function throwUnlessSuccessResponse(Response $response, FinancialTransactionInterface $transaction)
    {
        if ($response->isSuccess()) {
            return;
        }

        $transaction->setResponseCode($response->body->get('responseEnvelope.ack'));
        if ($response->body->has('payErrorList.payError(0).errorId')) {
            $transaction->setReasonCode($response->body->get('payErrorList.payError(0).errorId'));
        } else if ($response->body->get('error(0).errorId')) {
            $transaction->setReasonCode($response->body->get('error(0).errorId'));
        }
        
        $ex = new FinancialException('PayPal API call was not successful: '.$response);
        $ex->setFinancialTransaction($transaction);

        throw $ex;
    }

    protected function getReturnUrl(ExtendedDataInterface $data)
    {
        if ($data->has('return_url')) {
            return $data->get('return_url');
        }
        else if (0 !== strlen($this->returnUrl)) {
            return $this->returnUrl;
        }

        throw new \RuntimeException('You must configure a return url.');
    }

    protected function getCancelUrl(ExtendedDataInterface $data)
    {
        if ($data->has('cancel_url')) {
            return $data->get('cancel_url');
        }
        else if (0 !== strlen($this->cancelUrl)) {
            return $this->cancelUrl;
        }

        throw new \RuntimeException('You must configure a cancel url.');
    }
}