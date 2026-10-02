<?php

namespace App\Contracts;

interface WhatsAppSender
{
    /**
     * @param  string  $phone  E.164 number, e.g. +966501234567
     */
    public function send(string $phone, string $message): void;
}
