# Monero Block Scanner PHP

A PHP library for scanning and decrypting Monero blocks/transactions directly in PHP, focusing on efficiently identifying outputs sent to *any* of your subaddresses—even if you have millions. RingCT, subaddress, and view tag support included.

## Components

### MoneroScanner
Performs direct Monero blockchain scans, locating outputs that belong to large sets of subaddresses.

Handles both RPC data fetching and all local cryptographic parsing (see [How It Works](#how-it-works)).

### MoneroKeyDerivation
Wallet key derivation (from mnemonic) and subaddress generation.

A wrapper for deriving keys and subaddresses by mnemonic phrase (see [MoneroKeyDerivation API](#monerokeyderivation-api)).

## Why This Exists

At time of writing, there was no reliable, open-source PHP library capable of fully parsing Monero blocks, extracting all outputs, and efficiently matching them against very large subaddress sets—without depending on wallet RPC or incurring O(block_tx_count × address_count) work. Existing libraries lean on Monero’s wallet RPC, rarely decode blocks directly, and frequently lack modern features such as view tags, scalable subaddress lookup, or full RingCT amount decryption. This project fills that gap for those needing wallet sync, analytics, or auditing at scale.

- **Scalability**: Designed to work with millions of subaddresses through efficient cryptographic pre-filtering.
- **Modern protocol support**: Subaddresses, view tags, full RingCT parsing.
- **Privacy**: Keys never leave your environment—no Monero wallet RPC, no remote trust needed.
- **Script-ready**: Integrates into analytics, auditing, or wallet tooling.

The key derivation module provides full mnemonic-to-key/subaddress support for wallet integration.

## Architecture

This library operates in two phases:

### 1. Data Fetching (Online)
Fetch blocks and transactions from a Monero daemon via RPC.

### 2. Transaction Parsing (Offline)
All cryptographic logic runs locally and offline. The scanner returns candidate outputs that pass cryptographic pre-filtering, requiring final verification against your authoritative subaddress database (see [Expected False Positives](#expected-false-positives)).

## Requirements

- PHP 8.0+
- Extensions: `gmp`, `bcmath`, `curl`

Ubuntu/Debian install example:

```bash
sudo apt-get install php-gmp php-bcmath php-curl
```

## Quick Start

```bash
cd monero-scanner
php Example_MoneroScanner.php
php Example_MoneroKeyDerivation.php
```

## Usage

```php
<?php
require_once 'Class_MoneroScanner.php';
$scanner = new MoneroScanner('mainnet');

// Specify a Monero daemon RPC endpoint (You can find public endpoints at xmr.ditatompel.com/remote-nodes)
$rpc_url = 'http://node.example.com:18081';
$proxy = '127.0.0.1:9050'; // Optional, recommended. Can be null

// Step 1: Fetch block data
$block = $scanner->get_block_by_height(1234567, $rpc_url, $proxy);
if (isset($block['error'])) die("Error: " . $block['error']);

// Step 2: Extract candidate transactions (Cryptographically filtered: [~0.04% false positives](#expected-false-positives))
$candidates = $scanner->extract_transactions_to_me(
    $block['transactions'],
    '7c0edd...a51277' // Your private view key (64-char hex)
);

// Step 3: Verify candidates against your authoritative list (database, hash map, etc)
$verified_matches = [];
foreach ($candidates as $candidate) {
    if (is_subaddress_public_spend_key_mine($candidate['public_spend_key']) {
        $verified_matches[] = $candidate;
    }
}

// Display verified results
foreach ($verified_matches as $output) {
    echo $output['amount_xmr'] . " XMR received\n";
    echo "TX: " . $output['tx_hash'] . "\n";
    echo "Subaddress key: " . $output['public_spend_key'] . "\n";
}
```

> **IMPORTANT NOTICE:**  
> This library has been tested with ~100 Monero transactions and produced correct output in those cases. **HOWEVER: Thoroughly validate it with your own addresses and transaction history before relying on it in production/high-stakes use.** Edge cases or unusual Monero transactions may not be fully covered. Always verify accuracy for your data!

> **Feedback wanted:**  
> This is early and experimental. If you find bugs, have suggestions, or see edge cases, please open an issue or PR—community testing and review is extremely valuable at this stage.

## API Reference

### Block Fetching

```php
// By height
$block = $scanner->get_block_by_height($height, $rpc_url, $socks5_proxy = null);

// By hash
$block = $scanner->get_block_by_hash($hash, $rpc_url, $socks5_proxy = null);
```

Returns:
```php
[
    'height' => 1234567,
    'hash' => 'd8eb45da...b7f3961c',
    'timestamp' => 1702001234,
    'tx_count' => 70,
    'transactions' => [...],  // Array of decoded transactions
]
```

**HTTP Requests per Block:** Each block requires 1 + ceil(transaction_count / 100) requests:
- 1 for the block header & tx hashes
- 1 for every 100 transactions in the block

(Ex: 80 txs = 2 requests, 140 txs = 3 requests.)

### Transaction Extraction

```php
$candidates = $scanner->extract_transactions_to_me(
    $transactions,      // From block['transactions']
    $private_view_key   // 64-char hex string
);
```

Returns candidate outputs that pass cryptographic filtering. Verify each candidate against your authoritative subaddress list to eliminate false positives (see [Expected False Positives](#expected-false-positives)).

Returns an array like:
```php
[
    'tx_hash' => '...'           // 64-char hex string
    'output_index' => 0,         // Output index in tx
    'public_spend_key' => '...', // Subaddress public spend key
    'amount_xmr' => '0.123456789012',
    'amount_piconero' => 123456789012,
    'tx_public_key' => '...',
    'output_key' => '...',
    // Additional tx info:
    'tx_version' => 2,
    'unlock_time' => 0,
    'input_count' => 2,
    'output_count' => 2,
    'rct_type' => 6,
    'is_coinbase' => false,
]
```

## How It Works

The scanner uses cryptographic pre-filtering to efficiently identify potential matches with minimal false positives:

### Filtering Process

1. **View Tag Filtering:** Each output is tagged (1-byte) with a predictable value; 99.6% of irrelevant outputs are discarded immediately using cryptographic view tags.
2. **Key Recovery:** For remaining candidates, the subaddress public spend key is reconstructed using your view key.
3. **Amount Decryption:** RingCT amounts are decrypted and checked against safe limits (90% of remaining candidates filtered out).
4. **Verification Required:** Final candidates must be verified against your authoritative subaddress list.

### Expected False Positives

Results will contain false positives (~0.04% of candidates) that must be filtered against your authoritative subaddress list.

Approximately **0.04%** of all transaction outputs will be returned as false candidates. These false positives occur because:
- Random outputs may coincidentally pass view tag verification
- Amount decryption may succeed for non-owned outputs within safe limits

**Critical:** Always verify each candidate against your authoritative subaddress list. Do not assume candidates are legitimate without this verification step.

`if (!is_subaddress_public_spend_key_mine($tx['public_spend_key')) continue; // Irrelevant transaction`

### Mathematical Analysis

- **View tag filtering efficiency:** 99.6% of outputs discarded
- **Remaining after view tag filtering:** 100% - 99.6% = 0.4%
- **Safe amount filtering:** 90% of remaining outputs discarded
- **Final candidate rate:** 100 - 99.6 - ((100 - 99.6) × 0.9) = 100 - 99.6 - (0.4 × 0.9) = 100 - 99.6 - 0.36 = **0.04%**

### Helper Methods

All cryptographic methods are public:

```php
$scanner->check_view_tag($derivation, $output_index, $view_tag);
$scanner->recover_public_spend_key($derivation, $output_index, $output_key);
$scanner->decrypt_amount($derivation, $output_index, $encrypted_amount);
$scanner->parse_additional_pubkeys($extra_hex);
```

## MoneroKeyDerivation API

Helper (wrapper) class for easily generating/deriving keys and subaddresses from mnemonic phrase

### Key Derivation from Mnemonic

```php
require_once 'Class_MoneroKeyDerivation.php';

$key_derivation = new MoneroKeyDerivation();

// Recover wallet keys from your 25-word mnemonic
$keys = $key_derivation->derive_keys_from_mnemonic($mnemonic);

if (!isset($keys['error'])) {
    echo $keys['private_spend_key'];
    echo $keys['private_view_key'];
    echo $keys['public_spend_key'];
    echo $keys['public_view_key'];
}
```

### Subaddress Generation

```php
// Single subaddress
$subaddr = $key_derivation->generate_subaddress($mnemonic, $major_index, $minor_index);
// Main address (account 0, index 0)
$main = $key_derivation->get_main_address($mnemonic);
// Many subaddresses
$subaddrs = $key_derivation->generate_subaddresses($mnemonic, $major_index, $minor_index_start, $count);
```

Returns:
```php
[
    'address' => '78befa4b...d121e67b',
    'public_spend_key' => 'af414c3b...a290ad8c',
    'major_index' => 0,
    'minor_index' => 0,
]
```

## Project Structure

```
monero-scanner/
├── Class_MoneroScanner.php        # Blockchain scanning with cryptographic pre-filtering
├── Class_MoneroKeyDerivation.php  # Key derivation, address/subaddress creation
├── Example_MoneroScanner.php      # MoneroScanner usage
├── Example_MoneroKeyDerivation.php # MoneroKeyDerivation usage
├── README.md
└── lib/                           # PHP dependencies from monerophp
    ├── Cryptonote.php
    ├── ed25519.php
    ├── base58.php
    ├── Keccak.php
    ├── Varint.php
    ├── mnemonic.php
    └── wordsets/
        ├── english.ws.php
        ├── spanish.ws.php
        └── ... (other language wordlists)
```

## Dependencies

### Required Libraries

All required libraries are in `lib/`, vendored from [monero-integrations/monerophp](https://github.com/monero-integrations/monerophp). Source URLs are preserved as comments:

- [Keccak.php](https://github.com/kornrunner/php-keccak)
- [ed25519.php](https://github.com/monero-integrations/monerophp/blob/master/src/ed25519.php)
- [base58.php](https://github.com/monero-integrations/monerophp/blob/master/src/base58.php)
- [Varint.php](https://github.com/monero-integrations/monerophp/blob/master/src/Varint.php)
- [Cryptonote.php](https://github.com/monero-integrations/monerophp/blob/master/src/Cryptonote.php)
- [mnemonic.php](https://github.com/monero-integrations/monerophp/blob/master/src/mnemonic.php)
- `wordsets/` provides multi-language mnemonic support

**NOTE:** `Class_MoneroScanner.php` uses its own copies of `Varint` and `ed25519` internally to avoid dependency on protected members of upstream `Cryptonote`.

## License

MIT
