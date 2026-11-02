<?php

namespace App\Services\Suppliers\Contracts;

/**
 * Optional, for suppliers whose account may run below zero up to an allowance.
 * Supplier health shows it next to balance(), so a negative balance inside the
 * allowance is not mistaken for an empty account.
 */
interface HasCreditLine
{
    /** The allowance in USD, or null when unknown / not reported. */
    public function creditLine(): ?float;
}
