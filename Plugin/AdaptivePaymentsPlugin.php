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
use JMS\Payment\PaypalBundle\Client\Client;
use JMS\Payment\PaypalBundle\Client\Response;

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
     * @var \JMS\Payment\PaypalBundle\Client\Client
     */
    protected $client;

    /**
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param \JMS\Payment\PaypalBundle\Client\Client $client
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
        $this->createCheckoutBillingAgreement($transaction, 'Sale');
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

    protected function createCheckoutBillingAgreement(FinancialTransactionInterface $transaction, $paymentAction)
    {
        $data = $transaction->getExtendedData();

        $token = $this->obtainExpressCheckoutToken($transaction, $paymentAction);
        
        // TODO have this transmitted automatically by client
        // -H "X-PAYPAL-SECURITY-USERID: caller_1312486258_biz_api1.gmail.com" 
        // -H "X-PAYPAL-SECURITY-PASSWORD: 1312486294" 
        // -H "X-PAYPAL-SECURITY-SIGNATURE: AbtI7HV1xB428VygBUcIhARzxch4AL65.T18CTeylixNNxDZUu0iO87e" 
        // -H "X-PAYPAL-REQUEST-DATA-FORMAT: JSON" 
        // -H "X-PAYPAL-RESPONSE-DATA-FORMAT: JSON" 
        // -H "X-PAYPAL-APPLICATION-ID: APP-80W284485P519543T
        // https://svcs.sandbox.paypal.com/AdaptivePayments/Pay
        
        // TODO create client data as follows
        // "{\"actionType\":\"PAY\", \"currencyCode\":\"USD\", 
        // \"receiverList\":{\"receiver\":[{\"amount\":\"9.00\",
        // \"email\":\"rec1_1312486368_biz@gmail.com\",\"primary\":\"true\"}], 
        // \"receiver\":[{\"amount\":\"1.00\",\"email\":\"second@receiver.com\"}]}, 
        // \"returnUrl\":\"http://www.example.com/success.html\", 
        // \"cancelUrl\":\"http://www.example.com/failure.html\", 
        // \"requestEnvelope\":{\"errorLanguage\":\"en_US\", 
        // \"detailLevel\":\"ReturnAll\"}}
        
        $details = $this->client->requestGetExpressCheckoutDetails($token);
        $this->throwUnlessSuccessResponse($details, $transaction);

        // verify checkout status
        switch ($details->body->get('CHECKOUTSTATUS')) {
            case 'PaymentActionFailed':
                $ex = new FinancialException('PaymentAction failed.');
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode('PaymentActionFailed');
                $ex->setFinancialTransaction($transaction);

                throw $ex;

            case 'PaymentCompleted':
                break;

            case 'PaymentActionNotInitiated':
                break;

            default:
                $actionRequest = new ActionRequiredException('User has not yet authorized the transaction.');
                $actionRequest->setFinancialTransaction($transaction);
                $actionRequest->setAction(new VisitUrl($this->client->getAuthenticateExpressCheckoutTokenUrl($token)));

                throw $actionRequest;
        }

        // complete the transaction
        $data->set('paypal_payer_id', $details->body->get('PAYERID'));

        $parameters = array();
        $parameters['PAYMENTREQUEST_0_CURRENCYCODE'] = $transaction->getPayment()->getPaymentInstruction()->getCurrency();
        if (array_key_exists('PAYMENTREQUEST_0_SELLERPAYPALACCOUNTID', $data->get('checkout_params'))) {
            $tmp = $data->get('checkout_params');
            $parameters['PAYMENTREQUEST_0_SELLERPAYPALACCOUNTID'] = $tmp['PAYMENTREQUEST_0_SELLERPAYPALACCOUNTID'];
        }

        $response = $this->client->requestDoExpressCheckoutPayment(
            $data->get('express_checkout_token'),
            $transaction->getRequestedAmount(),
            $paymentAction,
            $details->body->get('PAYERID'),
            $parameters
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        switch($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS')) {
            case 'Completed':
                break;

            case 'Pending':
                $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
                
                throw new PaymentPendingException('Payment is still pending: '.$response->body->get('PAYMENTINFO_0_PENDINGREASON'));

            default:
                $ex = new FinancialException('PaymentStatus is not completed: '.$response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));
                $ex->setFinancialTransaction($transaction);
                $transaction->setResponseCode('Failed');
                $transaction->setReasonCode($response->body->get('PAYMENTINFO_0_PAYMENTSTATUS'));

                throw $ex;
        }

        $transaction->setReferenceNumber($response->body->get('PAYMENTINFO_0_TRANSACTIONID'));
        $transaction->setProcessedAmount($response->body->get('PAYMENTINFO_0_AMT'));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param string $paymentAction
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException if user has to authenticate the token
     *
     * @return string
     */
    protected function obtainExpressCheckoutToken(FinancialTransactionInterface $transaction, $paymentAction)
    {
        $data = $transaction->getExtendedData();
        if ($data->has('express_checkout_token')) {
            return $data->get('express_checkout_token');
        }

        $opts = $data->has('checkout_params') ? $data->get('checkout_params') : array();
        $opts['PAYMENTREQUEST_0_PAYMENTACTION'] = $paymentAction;
        $opts['PAYMENTREQUEST_0_CURRENCYCODE'] = $transaction->getPayment()->getPaymentInstruction()->getCurrency();

        $response = $this->client->requestSetExpressCheckout(
            $transaction->getRequestedAmount(),
            $this->getReturnUrl($data),
            $this->getCancelUrl($data),
            $opts
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        $data->set('express_checkout_token', $response->body->get('TOKEN'));

        $authenticateTokenUrl = $this->client->getAuthenticateExpressCheckoutTokenUrl($response->body->get('TOKEN'));

        $actionRequest = new ActionRequiredException('User must authorize the transaction.');
        $actionRequest->setFinancialTransaction($transaction);
        $actionRequest->setAction(new VisitUrl($authenticateTokenUrl));

        throw $actionRequest;
    }

    /**
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param \JMS\Payment\PaypalBundle\Client\Response $response
     * @return null
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    protected function throwUnlessSuccessResponse(Response $response, FinancialTransactionInterface $transaction)
    {
        if ($response->isSuccess()) {
            return;
        }

        $transaction->setResponseCode($response->body->get('ACK'));
        $transaction->setReasonCode($response->body->get('L_ERRORCODE0'));

        $ex = new FinancialException('PayPal-Response was not successful: '.$response);
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