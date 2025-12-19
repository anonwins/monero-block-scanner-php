<?php
/**
 * MoneroKeyDerivation - Key derivation and subaddress generation utilities
 *
 * Provides methods for deriving Monero keys from mnemonic phrases and generating subaddresses.
 */

// Required libraries (Some are modified for PHP 8+ compatibility)
require_once __DIR__ . '/lib/Keccak.php'; // https://github.com/kornrunner/php-keccak
require_once __DIR__ . '/lib/ed25519.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/ed25519.php
require_once __DIR__ . '/lib/base58.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/base58.php
require_once __DIR__ . '/lib/Varint.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/Varint.php
require_once __DIR__ . '/lib/Cryptonote.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/Cryptonote.php
require_once __DIR__ . '/lib/mnemonic.php';// https://github.com/monero-integrations/monerophp/blob/master/src/mnemonic.php

use MoneroIntegrations\MoneroPhp\Cryptonote;
use MoneroIntegrations\MoneroPhp\mnemonic;

class MoneroKeyDerivation
{
    private Cryptonote $cryptonote;

    public function __construct()
    {
        $this->cryptonote = new Cryptonote('mainnet');
    }

    /**
     * Derive Monero keys from a 25-word mnemonic phrase
     *
     * @param string $mnemonic_phrase 25-word mnemonic phrase
     * @return array Keys array or error array
     */
    public function derive_keys_from_mnemonic(string $mnemonic_phrase): array
    {
        try {
            // Split mnemonic into words
            $words = explode(' ', trim($mnemonic_phrase));

            // Validate mnemonic has correct number of words
            if (count($words) !== 25) {
                return ['error' => 'Invalid mnemonic: must contain exactly 25 words'];
            }

            // Decode mnemonic to seed
            $seed_hex = mnemonic::decode($words);

            // Generate private keys from seed
            $private_keys = $this->cryptonote->gen_private_keys($seed_hex);

            return [
                'private_spend_key' => $private_keys['spendKey'],
                'private_view_key' => $private_keys['viewKey'],
                'public_spend_key' => $this->cryptonote->pk_from_sk($private_keys['spendKey']),
                'public_view_key' => $this->cryptonote->pk_from_sk($private_keys['viewKey']),
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Failed to derive keys: ' . $e->getMessage()];
        }
    }

    /**
     * Generate a Monero subaddress from mnemonic and indices
     *
     * @param string $mnemonic_phrase 25-word mnemonic phrase
     * @param int $major_index Major index (account number)
     * @param int $minor_index Minor index (subaddress index within account)
     * @return array Subaddress data or error array
     */
    public function generate_subaddress(string $mnemonic_phrase, int $major_index, int $minor_index): array
    {
        try {
            // Validate indices
            if ($major_index < 0 || $minor_index < 0) {
                return ['error' => 'Invalid index values: must be non-negative'];
            }

            // First get the keys from mnemonic
            $keys = $this->derive_keys_from_mnemonic($mnemonic_phrase);
            if (isset($keys['error'])) {
                return $keys; // Return the error
            }

            // Generate subaddress
            $subaddress = $this->cryptonote->generate_subaddress(
                $major_index,
                $minor_index,
                $keys['private_view_key'],
                $keys['public_spend_key']
            );

            // Generate the subaddress's public spend key
            $subaddr_secret_key = $this->cryptonote->generate_subaddr_secret_key(
                $major_index,
                $minor_index,
                $keys['private_view_key']
            );

            $subaddr_public_spend_key = $this->cryptonote->generate_subaddress_spend_public_key(
                $keys['public_spend_key'],
                $subaddr_secret_key
            );

            return [
                'address' => $subaddress,
                'public_spend_key' => $subaddr_public_spend_key,
                'major_index' => $major_index,
                'minor_index' => $minor_index,
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Failed to generate subaddress: ' . $e->getMessage()];
        }
    }

    /**
     * Generate multiple subaddresses for an account
     *
     * @param string $mnemonic_phrase 25-word mnemonic phrase
     * @param int $major_index Major index (account number)
     * @param int $count Number of subaddresses to generate (starting from minor_index 0)
     * @return array Array of subaddress data or error array
     */
    public function generate_subaddresses(string $mnemonic_phrase, int $major_index, int $count): array
    {
        try {
            if ($major_index < 0) {
                return ['error' => 'Invalid major index: must be non-negative'];
            }
            if ($count <= 0) {
                return ['error' => 'Invalid count: must be positive'];
            }

            $subaddresses = [];
            for ($i = 0; $i < $count; $i++) {
                $result = $this->generate_subaddress($mnemonic_phrase, $major_index, $i);
                if (isset($result['error'])) {
                    return $result; // Return the error
                }
                $subaddresses[] = $result;
            }

            return $subaddresses;
        } catch (\Throwable $e) {
            return ['error' => 'Failed to generate subaddresses: ' . $e->getMessage()];
        }
    }

    /**
     * Get the main address (account 0, subaddress 0) from mnemonic
     *
     * @param string $mnemonic_phrase 25-word mnemonic phrase
     * @return array Main address data or error array
     */
    public function get_main_address(string $mnemonic_phrase): array
    {
        $result = $this->generate_subaddress($mnemonic_phrase, 0, 0);
        if (isset($result['error'])) {
            return $result;
        }

        // For main address, also include the full key set
        $keys = $this->derive_keys_from_mnemonic($mnemonic_phrase);
        if (isset($keys['error'])) {
            return $keys;
        }

        return array_merge($keys, $result);
    }

    /**
     * Validate a mnemonic phrase format (basic validation)
     *
     * @param string $mnemonic_phrase Mnemonic phrase to validate
     * @return bool True if format appears valid
     */
    public function validate_mnemonic_format(string $mnemonic_phrase): bool
    {
        $words = explode(' ', trim($mnemonic_phrase));
        return count($words) === 25 && !empty($words[0]);
    }
}

