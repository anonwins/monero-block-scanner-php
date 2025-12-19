# Monero Block Scanner PHP

A PHP library for scanning Monero blocks/transactions, optimized for extracting outputs sent to your subaddresses (including subaddress and RingCT support) in PHP.

## Components

### MoneroScanner
Scans the Monero blockchain for transactions addressed to specific subaddresses. Handles network RPC and local cryptography.

### MoneroKeyDerivation
Derives wallet keys from mnemonics and generates all required subaddresses.

## Why This Exists

Existing PHP libraries lacked comprehensive Monero scanning—especially subaddress support, view tags, correct RingCT amount decryption, and separation of online/offline handling. This library exists because:

- It supports **subaddresses** (not just legacy/main addresses).
- **Implements View Tag filtering** for highly efficient scanning.
- **Decrypts RingCT amounts** fully and accurately.
- All key operations are done **offline** for privacy and speed.
- The callback pattern allows scalable, customizable subaddress lookup, with strong safeguards against spurious matches.

The key derivation module enables wallet integration from mnemonics.

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
- **Flexible**: Use an array, database, or bloom filter for subaddress lookups. Just make sure your callback is precise.

```php
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $private_view_key,
    fn($key) => $bloom_filter->contains($key) // Fast lookup only; always confirm positives in your storage!
);
```

> **Note:**  
> The callback you provide, which determines if a recovered public spend key is yours, must be reliable and accurate. If your callback returns `true` for keys not belonging to you (for example, from a bloom filter false positive), invalid outputs may appear in your results. For more, see [Safety: Callback Reliability and Output Amount Limit](#safety-callback-reliability-and-output-amount-limit).
>
> The library does impose a configurable "safe" maximum amount per output as a last line of defense ([details here](#safety-callback-reliability-and-output-amount-limit)), but you should always ensure your callback is correct and confirm matches.

For best results, combine fast lookup (e.g., bloom filter) with a full in-memory or DB list for any positives. Generally you can expect ~2 calls to your callback per 100 transactions.

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

// Specify your Monero daemon RPC endpoint
$rpc_url = 'http://node.example.com:18081';

// Your private view key (64-char hex)
$private_view_key = '7c0edde48950a7c0edde48950a3a51277de48950a512777c0edde48950a51277';

// Define ownership check for subaddress public spend keys
function is_mine(string $public_spend_key): bool {
    static $my_keys = [
        'fc1d250d044cfd72e0e782187f88fbfa059d4fc3a6e8a4726e8a4f355be6ed29',
        'a6a97a0d7c0edde48950a512772d9bfba738a25489f1b2b9b923b9114761ecf0',
        // ... more keys as needed
    ];
    // In production, ensure this check is always thorough!
    return in_array($public_spend_key, $my_keys);
};

// Step 1: Fetch block data
$block = $scanner->get_block_by_height(1234567, $rpc_url, '127.0.0.1:9050');  // optional proxy

if (isset($block['error'])) {
    die("Error: " . $block['error']);
}

// Step 2: Offline scan for matching outputs
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $private_view_key,
    'is_mine'
);

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

> **Note:**  
> If your `$callback` yields `true` for an unrelated key, misleading outputs may be shown (including outputs with huge or blank amounts). It is essential your callback is accurate. As a last line of defense, a maximum output amount is enforced by the library, but you should review [Safety: Callback Reliability and Output Amount Limit](#safety-callback-reliability-and-output-amount-limit) for important precautions.
>

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
$subaddrs = $key_derivation->generate_subaddresses($mnemonic, $account_index, $count);
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
// Derive keys
$keys = $key_derivation->derive_keys_from_mnemonic($mnemonic);
// Generate subaddresses (e.g., account 0, 100 first addresses)
$subaddrs = $key_derivation->generate_subaddresses($mnemonic, 0, 100);

$public_spend_keys = array_column($subaddrs, 'public_spend_key');

// Fast check w/ bloom filter, always fallback to full check for positives
require_once 'Class_MoneroScanner.php';

$scanner = new MoneroScanner();
$block = $scanner->get_block_by_height($height, $rpc_url);
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $keys['private_view_key'],
    fn($key) => in_array($key, $public_spend_keys)
);
```
> **Tip:**  
> When using a bloom filter, confirm any matches in a reliable array or database before processing outputs. Remember, see [Safety: Callback Reliability and Output Amount Limit](#safety-callback-reliability-and-output-amount-limit) for the importance of accurate callback results and limits.

## How It Works

1. **View Tag Filtering:** Each output is tagged (1-byte) with a value predicted by `H("view_tag" || derivation || output_index)`. Non-matches are immediately skipped—this avoids 99.6% of work.
2. **Key Recovery:** For candidate outputs, the subaddress public spend key is reconstructed from output commitments and your view key.
3. **Ownership Check:** The recovered spend key is fed to your callback (array search, bloom filter + fallback, DB, etc).
4. **Amount Decryption:** If you own the output, RingCT amount decryption yields the true XMR received.

## Safety: Callback Reliability and Output Amount Limit

It is critical that your ownership-check callback (`fn($public_spend_key): bool`) is accurate and only returns `true` for keys you truly own. If it returns `true` for keys you don't own (e.g., due to a bloom filter false positive or overly broad logic), your scan results may include bogus transactions—typically with enormous amounts and invalid destinations. 

**To reduce the risk** from such callback mistakes, the library enforces a configurable maximum output amount (`$GLOBALS['MONERO_SCANNER_SAFE_XMR_AMOUNT']`, default: 9999 XMR). Outputs with an amount above this are automatically excluded from results as a final safeguard. However, **do not rely on this limit as your main defense**: always ensure your callback logic is accurate and confirm any matches via reliable methods (array/DB).

> The **safe amount** is extremely useful, since false outputs amounts are typically above 100_000 XMR.

> Even with this output amount filter, you should assume any surprising result is due to an overly-permissive callback, and review your matching process accordingly.

## Performance

Choose your lookup/callback algorithm according to subaddress count:

| Subaddresses | Array Lookup | Bloom Filter |
|--------------|-------------|-------------|
| 100          | ~0.1ms      | ~0.001ms    |
| 10,000       | ~10ms       | ~0.001ms    |
| 1,000,000    | ~1000ms     | ~0.001ms    |

**Tip:** For large sets, use a bloom filter for fast negatives and always confirm positives in a true array/DB. Even if your callback makes mistakes, outputs above 9999 XMR will be omitted by default.

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

