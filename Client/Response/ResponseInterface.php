<?php
namespace JMS\Payment\PaypalBundle\Client\Response;

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

class ResponseInterface
{
    /**
     * @param array $parameters
     */
    public function __construct(array $parameters)
    {
    }

    /**
     * @return boolean
     */
    public function isSuccess()
    {
    }

    /**
     * @return boolean
     */
    public function isPartialSuccess()
    {
    }

    /**
     * @return boolean
     */
    public function isError()
    {
    }

    /**
     * @return array(string:string)
     */
    public function getErrors()
    {
    }

    /**
     * @return string
     */
    public function __toString()
    {
    }
}