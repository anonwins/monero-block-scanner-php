# Monero Block Scanner PHP

A PHP library for scanning and decrypting Monero blocks/transactions directly in PHP, focusing on efficiently identifying outputs sent to *any* of your subaddresses—even if you have millions. RingCT, subaddress, and view tag support included.

## Components

### MoneroScanner
Performs direct Monero blockchain scans, locating outputs that belong to a huge set of subaddresses. Handles both RPC data fetching and all cryptographic parsing locally.

### MoneroKeyDerivation
Does all wallet key derivation (from your mnemonic) and generates subaddresses.

## Why This Exists

Before writing this, I tried hard to find any open-source PHP library that could actually do full block scanning—extract every transaction in a block, reconstruct all outputs, and tell me if any matched my own (potentially massive) list of subaddresses. Ideally, I wanted to just plug in my private view key and filter outputs across the chain *without* needing a Monero wallet RPC or doing O(block_tx_count × address_count) work.

But as of writing, no library existed that did real block parsing and scalable subaddress membership checking in PHP. I searched everywhere (GitHub, Packagist, etc.)—almost everything relied on Monero’s wallet RPC, not raw blockchain parsing, and nothing handled the modern features (view tags, scalable subaddress lookup, full RingCT amount decryption). This is a gap if you want to do your own auditing, wallet sync, or analytics, especially for large-scale address sets. So I built this to fill that hole.

- **Scalable to millions of subaddresses**: The callback model allows constant-time membership checks, e.g. via a bloom filter or DB.
- **Handles modern features**: Subaddresses, view tags for fast skipping, complete RingCT decryption, correct parsing.
- **Works fully offline**: All sensitive keys are used client-side only. No need to trust wallet RPC.
- **Customizable and script-friendly**: Use for analytics, auditing, or wallets.

The key derivation module is included for seamless wallet integration from mnemonics.

## Architecture

The library follows a two-phase design:

### 1. Data Fetching (Online)
Fetch blocks and transactions from a Monero daemon via RPC.

```php
$block = $scanner->get_block_by_height(1234567, 'http://node:18081', '127.0.0.1:9050');
```

### 2. Transaction Parsing (Offline)
All cryptographic processing (view tag, key derivation, amount decryption) is performed offline.
- **Fast**: No network latency during scanning.
- **Private**: Your view key is local only.
- **Flexible**: Use an array, database, or bloom filter for subaddress lookups.

```php
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $private_view_key,
    fn($key) => $bloom_filter->contains($key)
);
```

For callback design guidance and reliability, see [Safety: Callback Reliability and Output Amount Limit](#safety-callback-reliability-and-output-amount-limit).

## Requirements

- PHP 8.0+
- Extensions: `gmp`, `bcmath`, `curl`

Ubuntu/Debian install:

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

// Define ownership check for subaddress public spend keys
function is_public_spend_key_mine(string $public_spend_key): bool {
    return in_array($public_spend_key, [
        'fc1d250d044cfd72e0e782187f88fbfa059d4fc3a6e8a4726e8a4f355be6ed29',
        'a6a97a0d7c0edde48950a512772d9bfba738a25489f1b2b9b923b9114761ecf0',
        // ... more keys as needed
    ]);
}

// Step 2: Offline scan for matching outputs
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
> This library has only been tested with approximately 100 Monero transactions. All extracted results were correct in those tests. **HOWEVER, before using this tool in any production or high-stakes environment, you are strongly advised to thoroughly test it yourself with your own addresses and transaction history.** The code may not cover every possible edge case or unusual Monero transaction construction. Ensuring the accuracy of results for your use case is **your responsibility**. Do not rely on this library without personal verification on a representative dataset.

> **Feedback Wanted!**  
> This project is fresh and experimental—I'm the only person who has tried it so far, right after building it and publishing it to GitHub. I would really appreciate any bug reports, suggestions, or ideas about the code, docs, or features. If you run into issues, edge cases, or simply have thoughts to share, please open an issue or pull request—community feedback at this early stage is extremely helpful!

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
    'hash' => 'd8eb4805d7ba0678efa4af414c3ba203d29ad8cbd12167b65ff3908b7f39614c',
    'timestamp' => 1702001234,
    'tx_count' => 70,
    'transactions' => [...],  // Array of decoded transactions
]
```

**HTTP Requests per Block**: Each block requires 1 + ceil(transaction_count / 100) requests:
- 1 for the block header & tx hashes
- 1 extra for every 100 transactions

(Ex: 80 txs = 2 requests, 140 txs = 3 requests.)

### Transaction Extraction

```php
$matches = $scanner->extract_transactions_to_me(
    $transactions,      // From block['transactions']
    $private_view_key,  // 64-char hex string
    $callback           // fn(string $public_spend_key): bool
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
    'address' => '4ABC...',
    'public_spend_key' => '...',
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

// Assume you have saved the public spend keys in a database or hash table etc
$public_spend_keys = array_column($subaddrs, 'public_spend_key');

// Get the block
require_once 'Class_MoneroScanner.php';
$scanner = new MoneroScanner();
$block = $scanner->get_block_by_height($height, $rpc_url);

// Extract relevant transactions
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $keys['private_view_key'],
    fn($key) => in_array($key, $public_spend_keys)
);
```
> **Tip:**  
> For maximum scanning speed on a large address set, use a bloom filter in your callback—just remember that non-exact filters **will** require a final validation pass if you want to guard against the possibility of occasional false positives. See [Safety: Callback Reliability and Output Amount Limit](#safety-callback-reliability-and-output-amount-limit) for more detail.

## How It Works

1. **View Tag Filtering:** Each output is tagged (1-byte) with a value predicted by `H("view_tag" || derivation || output_index)`. Non-matches are immediately skipped—this avoids 99.6% of work.
2. **Key Recovery:** For candidate outputs, the subaddress public spend key is reconstructed from output commitments and your view key.
3. **Ownership Check:** The recovered spend key is passed to your callback (which could be an array search, bloom filter, database lookup, etc).
4. **Amount Decryption:** If you own the output, RingCT amount decryption yields the true XMR received.

## Safety: Callback Reliability and Output Amount Limit

### About False Positives

**Important:**  
The block scanner does **not** know your subaddresses or their public spend keys, and holds no database or internal list of them. Instead, it relies entirely on your callback function to decide if any reconstructed spend key matches one of your subaddresses. Therefore, the accuracy of your scan depends fully on the callback you provide.

- If your callback is precise (like checking an array or a database), results will be reliable and contain no false positives.
- If your callback is probabilistic or approximate (such as a bloom filter), you may get occasional false positives. In such cases, you must validate the scan results against your source of truth (your real subaddress set or wallet database) after the scan.

To further minimize accidental reporting, the scanner excludes outputs above a configurable safe maximum (`$GLOBALS['MONERO_SCANNER_SAFE_XMR_AMOUNT']`, default: 9999 XMR), as false positives from probabilistic filters may appear with implausibly large amounts because they cannot be decrypted with your keys.

## Performance

Choose your lookup/callback algorithm according to subaddress count:

| Subaddresses | Array Lookup | Bloom Filter |
|--------------|-------------|-------------|
| 100          | ~0.1ms      | ~0.001ms    |
| 10,000       | ~10ms       | ~0.001ms    |
| 1,000,000    | ~1000ms     | ~0.001ms    |

**Tip:** For large sets, use a bloom filter for very fast negatives. If you do, always validate candidates against your full list or DB of subaddresses/keys after scanning.

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

All dependencies live in `lib/`, sourced from [monero-integrations/monerophp](https://github.com/monero-integrations/monerophp). Source URLs are preserved as code comments:

- [Keccak.php](https://github.com/kornrunner/php-keccak)
- [ed25519.php](https://github.com/monero-integrations/monerophp/blob/master/src/ed25519.php)
- [base58.php](https://github.com/monero-integrations/monerophp/blob/master/src/base58.php)
- [Varint.php](https://github.com/monero-integrations/monerophp/blob/master/src/Varint.php)
- [Cryptonote.php](https://github.com/monero-integrations/monerophp/blob/master/src/Cryptonote.php)
- [mnemonic.php](https://github.com/monero-integrations/monerophp/blob/master/src/mnemonic.php)
- `wordsets/` for multi-language mnemonics

**NOTE:** `Class_MoneroScanner.php` constructs its own `Varint` and `ed25519` objects specifically to avoid reliance on protected properties inside the vendored `Cryptonote` code.

## License

MIT
