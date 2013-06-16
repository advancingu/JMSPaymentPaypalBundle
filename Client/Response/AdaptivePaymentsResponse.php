<?php

namespace JMS\Payment\PaypalBundle\Client\Response;

use Symfony\Component\HttpFoundation\ParameterBag;

/*
 * Copyright 2013 Markus Weiland <mweiland@graph-ix.net>
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

class AdaptivePaymentsResponse implements ResponseInterface
{
    public $body;

    public function __construct(array $parameters)
    {
        $this->body = new ParameterBag($parameters);
    }

    public function isSuccess()
    {
        $ack = $this->body->get('responseEnvelope.ack');

        return 'Success' === $ack || 'SuccessWithWarning' === $ack;
    }

    public function isPartialSuccess()
    {
        return false;
    }

    public function isError()
    {
        $ack = $this->body->get('responseEnvelope.ack');

        return 'Failure' === $ack || 'FailureWithWarning' === $ack;
    }

    public function getErrors()
    {
        $errors = array();
        $i = 0;
        while ($this->body->has('payErrorList.payError('.$i.').errorId')) {
            $errors[] = array(
                'code' => $this->body->get('payErrorList.payError('.$i.').errorId'),
                'short_message' => $this->body->get('payErrorList.payError('.$i.').message'),
                'long_message' => $this->body->get('payErrorList.payError('.$i.').message'),
            );

            $i++;
        }

        return $errors;
    }

    public function __toString()
    {
        if ($this->isError()) {
            $str = 'Debug-Token: '.$this->body->get('responseEnvelope.correlationId')."\n";

            foreach ($this->getErrors() as $error) {
                $str .= "{$error['code']}: {$error['short_message']} ({$error['long_message']})\n";
            }
        }
        else {
            $str = var_export($this->body->all(), true);
        }

        return $str;
    }
}