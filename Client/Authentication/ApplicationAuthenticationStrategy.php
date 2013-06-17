<?php

namespace JMS\Payment\PaypalBundle\Client\Authentication;

use JMS\Payment\CoreBundle\BrowserKit\Request;

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

class ApplicationAuthenticationStrategy implements AuthenticationStrategyInterface
{
    protected $username;
    protected $password;
    protected $signature;
    protected $applicationId;

    public function __construct($username, $password, $signature, $applicationId)
    {
        $this->username = $username;
        $this->password = $password;
        $this->signature = $signature;
        $this->applicationId = $applicationId;
    }

    public function authenticate(Request $request)
    {
        $request->headers->set('X-PAYPAL-SECURITY-USERID', $this->username);
        $request->headers->set('X-PAYPAL-SECURITY-PASSWORD', $this->password);
        $request->headers->set('X-PAYPAL-SECURITY-SIGNATURE', $this->signature);
        $request->headers->set('X-PAYPAL-APPLICATION-ID', $this->applicationId);
    }

    /**
     * (non-PHPdoc)
     * @see \JMS\Payment\PaypalBundle\Client\Authentication\AuthenticationStrategyInterface::getApiEndpoint()
     * @see https://developer.paypal.com/webapps/developer/docs/classic/api/endpoints/
     */
    public function getApiEndpoint($isDebug)
    {
        if ($isDebug) {
            return 'https://svcs.sandbox.paypal.com/AdaptivePayments';
        }
        else {
            return 'https://svcs.paypal.com/AdaptivePayments';
        }
    }
}