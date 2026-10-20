<?php
namespace App\Pay;
// Minimal external host contract; the plugin implementation is loaded unchanged.
interface Signature
{
    /** Match the ACG-Faka signature extension contract. */
    public function verification(array $data, array $config): bool;
}
