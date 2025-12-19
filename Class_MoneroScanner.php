<?php
/**
 * MoneroScanner - A PHP class for scanning Monero blockchain transactions
 * 
 * Provides methods to fetch blocks and identify transactions sent to specific subaddresses.
 */

// Required libraries (Some are modified for PHP 8+ compatibility)
require_once __DIR__ . '/lib/Keccak.php'; // https://github.com/kornrunner/php-keccak
require_once __DIR__ . '/lib/ed25519.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/ed25519.php
require_once __DIR__ . '/lib/base58.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/base58.php
require_once __DIR__ . '/lib/Varint.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/Varint.php
require_once __DIR__ . '/lib/Cryptonote.php'; // https://github.com/monero-integrations/monerophp/blob/master/src/Cryptonote.php

use MoneroIntegrations\MoneroPhp\Cryptonote;
use MoneroIntegrations\MoneroPhp\Varint;
use MoneroIntegrations\MoneroPhp\ed25519;

class MoneroScanner
{
    private Cryptonote $cryptonote;
    private Varint $varint;
    private ed25519 $ed25519;
    private int $batch_size = 100;

    public function __construct(string $network = 'mainnet')
    {
        $this->cryptonote = new Cryptonote($network);
        $this->varint = new Varint();
        $this->ed25519 = new ed25519();
    }

    /**
     * Set the batch size for fetching transactions
     */
    public function set_batch_size(int $size): void
    {
        $this->batch_size = max(1, $size);
    }

    /**
     * Fetch a complete block by height including all transactions
     * 
     * @param int $block_height The block height to fetch
     * @param string $rpc_host_port RPC endpoint (e.g., "http://127.0.0.1:18081")
     * @param string|null $socks5_host_port Optional SOCKS5 proxy (e.g., "127.0.0.1:9050")
     * @return array Block data with transactions, or error array
     */
    public function get_block_by_height(int $block_height, string $rpc_host_port, ?string $socks5_host_port = null): array
    {
        $proxy = $this->parse_proxy($socks5_host_port);
        
        // Fetch block info
        $block = $this->json_rpc_request($rpc_host_port, 'get_block', ['height' => $block_height], $proxy);
        
        if (isset($block['error'])) {
            return ['error' => 'Failed to fetch block: ' . $block['error']];
        }
        
        $block_hash = $block['block_header']['hash'] ?? null;
        if (!is_string($block_hash) || strlen($block_hash) !== 64) {
            return ['error' => 'Invalid block hash received'];
        }
        
        return $this->fetch_block_transactions($block, $rpc_host_port, $proxy);
    }

    /**
     * Fetch a complete block by hash including all transactions
     * 
     * @param string $block_hash The block hash to fetch
     * @param string $rpc_host_port RPC endpoint (e.g., "http://127.0.0.1:18081")
     * @param string|null $socks5_host_port Optional SOCKS5 proxy (e.g., "127.0.0.1:9050")
     * @return array Block data with transactions, or error array
     */
    public function get_block_by_hash(string $block_hash, string $rpc_host_port, ?string $socks5_host_port = null): array
    {
        $proxy = $this->parse_proxy($socks5_host_port);
        
        // Fetch block info by hash
        $block = $this->json_rpc_request($rpc_host_port, 'get_block', ['hash' => $block_hash], $proxy);
        
        if (isset($block['error'])) {
            return ['error' => 'Failed to fetch block: ' . $block['error']];
        }
        
        $retrieved_hash = $block['block_header']['hash'] ?? null;
        if (!is_string($retrieved_hash) || strlen($retrieved_hash) !== 64) {
            return ['error' => 'Invalid block hash received'];
        }
        
        return $this->fetch_block_transactions($block, $rpc_host_port, $proxy);
    }

    /**
     * Extract transactions that belong to the wallet
     * 
     * @param array $transactions Array of transaction data objects
     * @param string $private_view_key The wallet's private view key (64 hex chars)
     * @param callable $pubspend_callback Callback that takes a public spend key (string) and returns bool
     * @return array Array of matching outputs with amounts and metadata
     */
    public function extract_transactions_to_me(array $transactions, string $private_view_key, callable $pubspend_callback): array
    {
        $matching_outputs = [];
        
        foreach ($transactions as $tx) {
            $tx_hash = $tx['hash'] ?? '';
            $tx_data = $tx['data'] ?? null;
            
            if (!$tx_data) {
                continue;
            }
            
            $outputs = $this->process_transaction($tx_data, $tx_hash, $private_view_key, $pubspend_callback);
            
            foreach ($outputs as $output) {
                $matching_outputs[] = $output;
            }
        }
        
        return $matching_outputs;
    }

    /**
     * Process a single transaction and find matching outputs
     * 
     * @param object $tx_data Decoded transaction JSON object
     * @param string $tx_hash Transaction hash
     * @param string $private_view_key Private view key
     * @param callable $pubspend_callback Callback to check if spend key belongs to wallet
     * @return array Array of matching outputs
     */
    public function process_transaction(object $tx_data, string $tx_hash, string $private_view_key, callable $pubspend_callback): array
    {
        $matching_outputs = [];
        
        // Extract transaction public key from extra field
        if (!isset($tx_data->extra) || !is_array($tx_data->extra)) {
            return [];
        }
        
        $extra_hex = bin2hex(pack('C*', ...$tx_data->extra));
        $tx_public_key = $this->cryptonote->txpub_from_extra($extra_hex);
        
        if (!$tx_public_key) {
            return [];
        }
        
        // Parse additional tx public keys from extra field (for subaddress outputs)
        $additional_tx_pubkeys = $this->parse_additional_pubkeys($extra_hex);
        
        // Check if transaction has outputs
        if (!isset($tx_data->vout) || !is_array($tx_data->vout)) {
            return [];
        }
        
        // Process each output
        foreach ($tx_data->vout as $output_index => $output) {
            // Get output key and view tag
            if (!isset($output->target->tagged_key)) {
                continue;
            }
            
            $output_key = $output->target->tagged_key->key ?? null;
            $view_tag = $output->target->tagged_key->view_tag ?? null;
            
            if (!$output_key || $view_tag === null) {
                continue;
            }
            
            // Determine tx public key for this output
            $tx_pub_key_for_output = $tx_public_key;
            if ($output_index >= 1 && isset($additional_tx_pubkeys[$output_index - 1])) {
                $tx_pub_key_for_output = $additional_tx_pubkeys[$output_index - 1];
            }
            
            // Compute key derivation
            $derivation = $this->cryptonote->gen_key_derivation($tx_pub_key_for_output, $private_view_key);
            
            // Check view tag for quick filtering
            $view_tag_matches = $this->check_view_tag($derivation, $output_index, $view_tag);
            
            // For subaddress outputs, try the additional pubkey at THIS output's index
            if (!$view_tag_matches && isset($additional_tx_pubkeys[$output_index])) {
                $add_pubkey = $additional_tx_pubkeys[$output_index];
                $alt_derivation = $this->cryptonote->gen_key_derivation($add_pubkey, $private_view_key);
                
                if ($this->check_view_tag($alt_derivation, $output_index, $view_tag)) {
                    $view_tag_matches = true;
                    $derivation = $alt_derivation;
                    $tx_pub_key_for_output = $add_pubkey;
                }
            }
            
            if (!$view_tag_matches) {
                continue;
            }
            
            // View tag matched - recover the subaddress public spend key from the output
            $recovered_spend_key = $this->recover_public_spend_key($derivation, $output_index, $output_key);
            
            // Check if this spend key belongs to our wallet
            if (!$pubspend_callback($recovered_spend_key)) {
                continue;
            }
            
            // This output belongs to us - decrypt the amount
            $encrypted_amount = $tx_data->rct_signatures->ecdhInfo[$output_index]->amount ?? null;
            
            if (!$encrypted_amount) {
                continue;
            }
            
            $amount_data = $this->decrypt_amount($derivation, $output_index, $encrypted_amount);
            
            // Extract additional transaction metadata
            $tx_version = $tx_data->version ?? null;
            $unlock_time = $tx_data->unlock_time ?? null;
            $input_count = isset($tx_data->vin) ? count($tx_data->vin) : 0;
            $output_count = isset($tx_data->vout) ? count($tx_data->vout) : 0;

            // Extract RingCT type from rct_signatures
            $rct_type = null;
            if (isset($tx_data->rct_signatures) && isset($tx_data->rct_signatures->type)) {
                $rct_type = $tx_data->rct_signatures->type;
            }

            // Calculate fee if possible (simplified: sum outputs - sum inputs for coinbase, or 0 for regular tx)
            $fee = null;
            if ($input_count === 1 && isset($tx_data->vin[0]->gen)) {
                // Coinbase transaction - fee is difference between block reward and outputs
                $total_outputs = 0;
                foreach ($tx_data->vout as $out) {
                    if (isset($out->amount)) {
                        $total_outputs += $out->amount;
                    }
                }
                // Note: We'd need block reward calculation for accurate fee
            }

            $matching_outputs[] = [
                'tx_hash' => $tx_hash,
                'output_index' => $output_index,
                'public_spend_key' => $recovered_spend_key,
                'amount_xmr' => $amount_data['xmr'],
                'amount_piconero' => $amount_data['piconero'],
                'tx_public_key' => $tx_public_key,
                'output_key' => $output_key,
                // Additional transaction metadata
                'tx_version' => $tx_version,
                'unlock_time' => $unlock_time,
                'input_count' => $input_count,
                'output_count' => $output_count,
                'rct_type' => $rct_type,
                'is_coinbase' => $input_count === 1 && isset($tx_data->vin[0]->gen),
            ];
        }
        
        return $matching_outputs;
    }

    /**
     * Check if a view tag matches the expected value
     */
    public function check_view_tag(string $derivation, int $output_index, string $view_tag): bool
    {
        $output_index_varint_hex = $this->varint->encode_varint($output_index);
        $view_tag_prefix = bin2hex("view_tag");
        $view_tag_hash = $this->cryptonote->keccak_256($view_tag_prefix . $derivation . $output_index_varint_hex);
        $expected_view_tag = substr($view_tag_hash, 0, 2);
        $view_tag_hex = str_pad($view_tag, 2, '0', STR_PAD_LEFT);

        return $view_tag_hex === $expected_view_tag;
    }

    /**
     * Recover the subaddress public spend key from an output
     * This is the reverse of derive_public_key: D = P - H_s(derivation || index) * G
     */
    public function recover_public_spend_key(string $derivation, int $output_index, string $output_key): string
    {
        // Compute scalar = H_s(derivation || varint(output_index))
        $scalar = $this->cryptonote->derivation_to_scalar($derivation, $output_index);

        // Compute scalar * G
        $sG = $this->ed25519->scalarmult_base($this->ed25519->decodeint(hex2bin($scalar)));

        // Negate sG: for Ed25519, negate x-coordinate (x, y) -> (-x mod q, y)
        $neg_sG = [
            gmp_mod(gmp_neg($sG[0]), $this->ed25519->q),
            $sG[1]
        ];

        // Decode the output public key P
        $P = $this->ed25519->decodepoint(hex2bin($output_key));

        // Compute D = P + (-sG) = P - sG
        $D = $this->ed25519->edwards($P, $neg_sG);

        // Encode back to hex
        return bin2hex($this->ed25519->encodepoint($D));
    }

    /**
     * Decrypt RingCT amount
     */
    public function decrypt_amount(string $derivation, int $output_index, string $encrypted_amount): array
    {
        $scalar = $this->cryptonote->derivation_to_scalar($derivation, $output_index);
        $amount_key_full = $this->cryptonote->keccak_256(bin2hex("amount") . $scalar);
        $amount_key = substr($amount_key_full, 0, 16);
        
        $encrypted_bin = hex2bin($encrypted_amount);
        $key_bin = hex2bin($amount_key);
        $decrypted_bin = $encrypted_bin ^ $key_bin;
        
        $amount_int = unpack('P', $decrypted_bin)[1];
        $amount_xmr = bcdiv((string)$amount_int, '1000000000000', 12);
        
        return [
            'piconero' => $amount_int,
            'xmr' => $amount_xmr,
        ];
    }

    /**
     * Parse additional tx public keys from extra field
     */
    public function parse_additional_pubkeys(string $extra_hex): array
    {
        $additional_pubkeys = [];
        $extra_bin = hex2bin($extra_hex);
        $pos = 0;
        $len = strlen($extra_bin);
        
        while ($pos < $len) {
            if ($pos >= $len) break;
            
            $tag = ord($extra_bin[$pos]);
            $pos++;
            
            if ($tag === 0x01) {
                // TX public key (32 bytes)
                $pos += 32;
            } elseif ($tag === 0x02) {
                // Nonce/encrypted payment ID
                if ($pos >= $len) break;
                $nonce_len = ord($extra_bin[$pos]);
                $pos++;
                $pos += $nonce_len;
            } elseif ($tag === 0x04) {
                // Additional public keys
                if ($pos >= $len) break;
                $count = ord($extra_bin[$pos]);
                $pos++;
                for ($i = 0; $i < $count && $pos + 32 <= $len; $i++) {
                    $additional_pubkeys[] = bin2hex(substr($extra_bin, $pos, 32));
                    $pos += 32;
                }
            } else {
                // Unknown tag - try to skip
                if ($pos >= $len) break;
                $skip_len = ord($extra_bin[$pos]);
                $pos++;
                $pos += $skip_len;
            }
        }
        
        return $additional_pubkeys;
    }

    /**
     * Make a JSON-RPC request to Monero daemon
     */
    public function json_rpc_request(string $rpc_host_port, string $method, array $params, ?array $proxy): array
    {
        $url = rtrim($rpc_host_port, '/') . '/json_rpc';
        
        $post_data = [
            'jsonrpc' => '2.0',
            'id' => '0',
            'method' => $method,
            'params' => $params
        ];
        
        $ch = curl_init($url);
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($post_data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];
        
        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy['host'];
            $options[CURLOPT_PROXYPORT] = $proxy['port'];
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        if ($response === false) {
            return ['error' => "cURL error: $curl_error"];
        }
        
        if ($http_code !== 200) {
            return ['error' => "HTTP error: $http_code"];
        }
        
        $decoded = json_decode($response, true);
        if (!$decoded) {
            return ['error' => 'Failed to decode JSON response'];
        }
        
        if (isset($decoded['error'])) {
            return ['error' => $decoded['error']['message'] ?? 'Unknown RPC error'];
        }
        
        return $decoded['result'] ?? [];
    }

    /**
     * Make a custom RPC request to Monero daemon (for non-JSON-RPC endpoints)
     */
    public function custom_rpc_request(string $url, array $post_data, ?array $proxy): array
    {
        $ch = curl_init($url);
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($post_data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];
        
        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy['host'];
            $options[CURLOPT_PROXYPORT] = $proxy['port'];
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        if ($response === false) {
            return ['error' => "cURL error: $curl_error"];
        }
        
        if ($http_code !== 200) {
            return ['error' => "HTTP error: $http_code"];
        }
        
        $decoded = json_decode($response, true);
        if (!$decoded) {
            return ['error' => 'Failed to decode JSON response'];
        }
        
        return $decoded;
    }

    /**
     * Get the underlying Cryptonote instance
     */
    public function get_cryptonote(): Cryptonote
    {
        return $this->cryptonote;
    }

    /**
     * Parse SOCKS5 proxy string into array
     */
    private function parse_proxy(?string $socks5_host_port): ?array
    {
        if (!$socks5_host_port) {
            return null;
        }
        
        $parts = explode(':', $socks5_host_port);
        if (count($parts) !== 2) {
            return null;
        }
        
        return [
            'host' => $parts[0],
            'port' => (int)$parts[1],
        ];
    }

    /**
     * Fetch all transactions for a block in batches
     */
    private function fetch_block_transactions(array $block, string $rpc_host_port, ?array $proxy): array
    {
        $tx_hashes = $block['tx_hashes'] ?? [];
        $block_height = $block['block_header']['height'] ?? 0;
        $block_hash = $block['block_header']['hash'] ?? '';
        $block_timestamp = $block['block_header']['timestamp'] ?? 0;
        
        $transactions = [];
        
        if (count($tx_hashes) === 0) {
            return [
                'height' => $block_height,
                'hash' => $block_hash,
                'timestamp' => $block_timestamp,
                'tx_count' => 0,
                'transactions' => [],
            ];
        }
        
        // Fetch transactions in batches
        $batches = array_chunk($tx_hashes, $this->batch_size);
        
        foreach ($batches as $batch_hashes) {
            $post_data = [
                'txs_hashes' => $batch_hashes,
                'decode_as_json' => true,
                'prune' => false
            ];
            
            $url = rtrim($rpc_host_port, '/') . '/get_transactions';
            $response = $this->custom_rpc_request($url, $post_data, $proxy);
            
            if (isset($response['error'])) {
                return ['error' => 'Failed to fetch transactions: ' . $response['error']];
            }
            
            if (($response['status'] ?? '') !== 'OK') {
                return ['error' => 'Failed to fetch transactions: bad status'];
            }
            
            $txs = $response['txs'] ?? [];
            
            foreach ($txs as $tx_idx => $tx_response) {
                $tx_hash = $batch_hashes[$tx_idx];
                $tx_json = $tx_response['as_json'] ?? null;
                
                if (!$tx_json) {
                    continue;
                }
                
                $tx_data = json_decode($tx_json);
                if (!$tx_data) {
                    continue;
                }
                
                $transactions[] = [
                    'hash' => $tx_hash,
                    'data' => $tx_data,
                    'block_height' => $block_height,
                    'block_timestamp' => $block_timestamp,
                ];
            }
        }
        
        return [
            'height' => $block_height,
            'hash' => $block_hash,
            'timestamp' => $block_timestamp,
            'tx_count' => count($transactions),
            'transactions' => $transactions,
        ];
    }
}

