# Monero Block Scanner PHP

A PHP library for scanning and decrypting Monero blocks/transactions directly in PHP, focusing on efficiently identifying outputs sent to *any* of your subaddresses—even if you have millions. RingCT, subaddress, and view tag support included.

## Components

### MoneroScanner
Performs direct Monero blockchain scans, locating outputs that belong to large sets of subaddresses. Handles both RPC data fetching and all cryptographic parsing locally.

### MoneroKeyDerivation
Wallet key derivation (from mnemonic) and subaddress generation.

## Why This Exists

At time of writing, there was no reliable, open-source PHP library capable of fully parsing Monero blocks, extracting all outputs, and efficiently matching them against very large subaddress sets—without depending on wallet RPC or incurring O(block_tx_count × address_count) work. Existing libraries lean on Monero’s wallet RPC, rarely decode blocks directly, and frequently lack modern features such as view tags, scalable subaddress lookup, or full RingCT amount decryption. This project fills that gap for those needing wallet sync, analytics, or auditing at scale.

- **Scalability**: Designed to work with millions of subaddresses, using a callback approach for fast lookup (arrays, databases, etc).
- **Modern protocol support**: Subaddresses, view tags, full RingCT parsing.
- **Privacy**: Keys never leave your environment—no Monero wallet RPC, no remote trust needed.
- **Script-ready**: Integrates into analytics, auditing, or wallet tooling.

The key derivation module provides full mnemonic-to-key/subaddress support for wallet integration.

## Architecture

This library is two-phase:

### 1. Data Fetching (Online)
Fetch blocks and transactions from a Monero daemon via RPC.

```php
$block = $scanner->get_block_by_height(1234567, 'http://node:18081', '127.0.0.1:9050');
```

### 2. Transaction Parsing (Offline)
All cryptographic logic (view tags, key derivation, amount decryption) runs locally and offline.
- **Fast**: No network latency when scanning.
- **Private**: View key never leaves your machine.
- **Flexible**: Supply your own lookup for subaddress sets (array, DB, etc).

```php
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $private_view_key,
    function($public_spend_key) {
        // Return true if the public_spend_key matches one you control.
        return in_array($public_spend_key, $your_public_spend_keys_array);
    }
);
```

> **Callback accuracy matters:** The callback should accurately report whether a public spend key is controlled by you (see ["Safety: Callback Reliability and Output Amount Limit"](#safety-callback-reliability-and-output-amount-limit) for guidance). Arrays, hash maps, or database-backed lookups are recommended for maximum correctness and minimal false positives. Probabilistic structures (like bloom filters) are not really needed, as MoneroScanner already heavily pre-filters outputs by cryptographic properties.

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

// Specify a Monero daemon RPC endpoint
// You can find public endpoints at xmr.ditatompel.com/remote-nodes
$rpc_url = 'http://node.example.com:18081';
$proxy = '127.0.0.1:9050'; // Optional, recommended. Can be null

// Step 1: Fetch block data
$block = $scanner->get_block_by_height(1234567, $rpc_url, $proxy);
if (isset($block['error'])) die("Error: " . $block['error']);

// Step 2: Offline scan for matching outputs
// Your subaddresses should be supplied as an array/hashmap/database or other authoritative source.
function is_public_spend_key_mine(string $public_spend_key): bool {
    return in_array($public_spend_key, [
        'fc1d250d...5be6ed29',
        'a6a97a0d...edde4895',
        // ... your subaddress public spend keys here
    ]);
}

$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    '7c0edd...a51277', // Your private view key (64-char hex)
    'is_public_spend_key_mine'
);

// Display results
foreach ($matches as $output) {
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
$matches = $scanner->extract_transactions_to_me(
    $transactions,      // From block['transactions']
    $private_view_key,  // 64-char hex string
    $callback           // function(string $public_spend_key): bool
);
```

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

### Helper Methods

All cryptographic methods are public:

```php
$scanner->check_view_tag($derivation, $output_index, $view_tag);
$scanner->recover_public_spend_key($derivation, $output_index, $output_key);
$scanner->decrypt_amount($derivation, $output_index, $encrypted_amount);
$scanner->parse_additional_pubkeys($extra_hex);
$scanner->set_batch_size(100); // For RPC transaction fetch batching
```

## MoneroKeyDerivation API

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

### Integration: Fast Scanning

```php
// Derive keys from mnemonic
$keys = $key_derivation->derive_keys_from_mnemonic($mnemonic);

// Generate 100 subaddresses (e.g., major index 0, minor index 0-99)
$subaddrs = $key_derivation->generate_subaddresses($mnemonic, 0, 0, 100);

// Collect the public spend keys in your preferred authoritative structure
$public_spend_keys = array_column($subaddrs, 'public_spend_key');

// Get a block
require_once 'Class_MoneroScanner.php';
$scanner = new MoneroScanner();
$block = $scanner->get_block_by_height($height, $rpc_url);

// Extract relevant transactions
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $keys['private_view_key'],
    function(string $public_spend_key) use ($public_spend_keys) {
        return in_array($public_spend_key, $public_spend_keys);
    }
);
```

## How It Works

1. **View Tag Filtering:** Each output is tagged (1-byte) with a predictable value; almost all non-matches are skipped up front.
2. **Key Recovery:** For candidate outputs, the subaddress public spend key is reconstructed using your view key.
3. **Ownership Check:** The reconstructed key is passed to your callback, which determines if the output belongs to you.
4. **Amount Decryption:** If owned, the RingCT amount is decrypted, and the transaction will be in the return results.

## Safety: Callback Reliability and Output Amount Limit

### About False Positives

**Important:**  
The scanner never knows or stores your subaddresses. It depends on your callback to determine ownership, so results are only as reliable as the data behind your callback.

1. The **View Tag** is used to pre-filter out 99.6% of irrelevant tx outputs (99% filtered out)
2. The **Callback** is called and if it returns true, the process continues (+ 0-100% filtered-out)
3. The **Safe Amount** is used to reject outputs with absurd amounts (since amount decryption fails + 90% filtered-out)
4. Transactions that survive all this filtering will be returned.

- If your callback is precise (accurate array/database/lookup), results will be reliable with no false positives.
- Approximate/probabilistic checks (like bloom filters) will **rarely (but not never)** deliver a false positive.
- Returning always true will result in ~1 false positive per ~100 transactions, before getting further filtered by output amount limit `safe amount` (`$GLOBALS['MONERO_SCANNER_SAFE_XMR_AMOUNT']`, default: 9999 XMR), as most ciphertexts cannot be validly decrypted with your keys.

**TODO:** I will be switching step 2 with step 3, so that the callback will be called last. Database lookups are more expensive than amount decryption.

## Project Structure

```
monero-scanner/
├── Class_MoneroScanner.php        # Blockchain scanning (see extract_transactions_to_me for callback/amount limit safety)
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




