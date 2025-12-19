# Monero Block Scanner PHP

A PHP library for scanning Monero blocks/transactions and extracting outputs sent to your subaddresses.

## Components

### MoneroScanner
Scans the Monero blockchain for transactions sent to specific subaddresses.

### MoneroKeyDerivation
Derives keys from mnemonic phrases and generates subaddresses.

## Why This Exists

I needed a way to scan the Monero blockchain for incoming transactions to a set of subaddresses. Surprisingly, I couldn't find an existing PHP solution that handled this properly—especially one that:

- Supports subaddresses (not just the main address)
- Implements view tags for efficient filtering
- Decrypts RingCT amounts correctly
- Works offline after fetching the raw data

So I built one. The key derivation component was added to support complete wallet operations.

## Architecture

The library is split into two distinct phases:

### 1. Data Fetching (Online)
Communicates with a Monero daemon via RPC to retrieve block and transaction data. This is the only part that requires network access.

```php
$block = $scanner->get_block_by_height(3408787, 'http://node:18081');
```

### 2. Transaction Parsing (Offline)
All cryptographic operations—view tag checking, key derivation, amount decryption—happen locally with no network calls. This makes the parsing phase:

- **Fast**: No network latency
- **Private**: Your view key never leaves your machine
- **Scalable**: Plug in a bloom filter for O(1) subaddress lookups

```php
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $private_view_key,
    fn($key) => $bloom_filter->contains($key)  // Your lookup, your rules
);
```

## Requirements

- PHP 8.0+
- Extensions: `gmp`, `bcmath`, `curl`

Ubuntu/Debian:

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

// RPC endpoint (Monero daemon)
$rpc_url = 'http://node.example.com:18081';

// Your private view key (hex, 64 chars)
$private_view_key = '7c0edde48950a7c0edde48950a3a51277de48950a512777c0edde48950a51277';

// Define how to check if a public spend key belongs to your wallet
function is_mine(string $pubspend_key): bool {
    static $my_keys = [
        // Simple array lookup (or use a bloom filter for large sets)
        'fc1d250d044cfd72e0e782187f88fbfa059d4fc3a6e8a4726e8a4f355be6ed29',
        'a6a97a0d7c0edde48950a512772d9bfba738a25489f1b2b9b923b9114761ecf0',
        // ... your subaddress public spend keys
    ];
    return in_array($pubspend_key, $my_keys);
};

// Fetch block (online)
$block = $scanner->get_block_by_height(
    1234567,
    $rpc_url,
    '127.0.0.1:9050'  // Optional SOCKS5 proxy
);

if (isset($block['error'])) {
    die("Error: " . $block['error']);
}

// Parse transactions (offline)
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $private_view_key,
    'is_mine' // The function that checks if a public spend key belongs to one of your subaddresses
);

foreach ($matches as $output) {
    echo $output['amount_xmr'] . " XMR received\n";
    echo "TX: " . $output['tx_hash'] . "\n";
    echo "Subaddress key: " . $output['public_spend_key'] . "\n";
}
```

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

**HTTP Requests per Block**: Each block height requires 1 + ceil(tx_count / 100) HTTP requests to the RPC node:
- 1 request to fetch block header and transaction hashes
- 1 additional request per 100 transactions

Example: A block with 80 transactions = 2 requests. With 140 transactions = 3 requests.

### Transaction Extraction

```php
$matches = $scanner->extract_transactions_to_me(
    $transactions,      // From block['transactions']
    $private_view_key,  // 64-char hex string
    $callback           // fn(string $pubspend): bool
);
```

Returns array of matching outputs:
```php
[
    'tx_hash' => '...',           // Transaction hash (64-char hex)
    'output_index' => 0,          // Index of this output in the transaction
    'public_spend_key' => '...',  // Recovered subaddress public spend key
    'amount_xmr' => '0.123456789012',     // Amount in XMR (string for precision)
    'amount_piconero' => 123456789012,    // Amount in piconero (int)
    'tx_public_key' => '...',     // Transaction public key
    'output_key' => '...',        // Output public key
    // Additional transaction metadata:
    'tx_version' => 2,            // Transaction version
    'unlock_time' => 0,           // Unlock time (block height or timestamp)
    'input_count' => 2,           // Number of inputs
    'output_count' => 2,          // Number of outputs
    'rct_type' => 6,              // RingCT type (1=simple, 2=full, 3=bulletproof, 4=bulletproof2, 5=cryptonight_r, 6=clsag)
    'is_coinbase' => false,       // Whether this is a coinbase (miner reward) transaction
]
```

### Helper Methods

All internals are public if you need them:

```php
$scanner->check_view_tag($derivation, $output_index, $view_tag);
$scanner->recover_public_spend_key($derivation, $output_index, $output_key);
$scanner->decrypt_amount($derivation, $output_index, $encrypted_amount);
$scanner->parse_additional_pubkeys($extra_hex);
$scanner->set_batch_size(100);  // Transactions per RPC call
```

## MoneroKeyDerivation API

### Key Derivation from Mnemonic

```php
require_once 'Class_MoneroKeyDerivation.php';

$key_derivation = new MoneroKeyDerivation();

// Derive keys from 25-word mnemonic
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
// Generate specific subaddress
$subaddr = $key_derivation->generate_subaddress($mnemonic, $major_index, $minor_index);

// Generate main address (account 0, subaddress 0)
$main = $key_derivation->get_main_address($mnemonic);

// Generate multiple subaddresses
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

### Integration with MoneroScanner

```php
// Derive keys and generate subaddresses
$keys = $key_derivation->derive_keys_from_mnemonic($mnemonic);
$subaddrs = $key_derivation->generate_subaddresses($mnemonic, 0, 100);

// Extract public spend keys for scanning
$pubspend_keys = array_column($subaddrs, 'public_spend_key');

// Use with bloom filter for efficient lookups
require_once 'Class_MoneroScanner.php';

$scanner = new MoneroScanner();
$block = $scanner->get_block_by_height($height, $rpc_url);
$matches = $scanner->extract_transactions_to_me(
    $block['transactions'],
    $keys['private_view_key'],
    fn($key) => in_array($key, $pubspend_keys)
);
```

## How It Works

1. **View Tag Filtering**: Each output has a 1-byte view tag. We compute the expected tag using `H("view_tag" || derivation || output_index)` and skip non-matching outputs immediately. This eliminates ~99.6% of outputs without expensive point operations.

2. **Key Recovery**: For outputs that pass the view tag check, we recover the subaddress public spend key: `D = P - H_s(derivation || index) * G`

3. **Ownership Check**: The recovered key is passed to your callback. If you're monitoring 1000 subaddresses with a bloom filter, this is O(1).

4. **Amount Decryption**: For confirmed outputs, we decrypt the RingCT amount using `amount = encrypted_amount XOR H("amount" || scalar)[0:8]`

## Performance

The callback-based design means you control the lookup complexity:

| Subaddresses | Array Lookup | Bloom Filter |
|--------------|--------------|--------------|
| 100          | ~0.1ms       | ~0.001ms     |
| 10,000       | ~10ms        | ~0.001ms     |
| 1,000,000    | ~1000ms      | ~0.001ms     |

For production systems with many subaddresses, use a bloom filter.

## Project Structure

```
monero-scanner/
├── Class_MoneroScanner.php        # Blockchain scanning class
├── Class_MoneroKeyDerivation.php  # Key derivation and subaddress generation
├── Example_MoneroScanner.php      # MoneroScanner usage example
├── Example_MoneroKeyDerivation.php # MoneroKeyDerivation usage example
├── README.md
└── lib/                           # Dependencies from monerophp
    ├── Cryptonote.php
    ├── ed25519.php
    ├── base58.php
    ├── Keccak.php
    ├── Varint.php
    ├── mnemonic.php
    └── wordsets/                   # Mnemonic word lists
        ├── english.ws.php
        ├── spanish.ws.php
        └── ...
```

## Dependencies

### Required Libraries

The `lib/` folder contains files from [monero-integrations/monerophp](https://github.com/monero-integrations/monerophp). Each file includes a comment with its source URL if you need to fetch the latest version:

- [Keccak.php](https://github.com/kornrunner/php-keccak)
- [ed25519.php](https://github.com/monero-integrations/monerophp/blob/master/src/ed25519.php)
- [base58.php](https://github.com/monero-integrations/monerophp/blob/master/src/base58.php)
- [Varint.php](https://github.com/monero-integrations/monerophp/blob/master/src/Varint.php)
- [Cryptonote.php](https://github.com/monero-integrations/monerophp/blob/master/src/Cryptonote.php)
- [mnemonic.php](https://github.com/monero-integrations/monerophp/blob/master/src/mnemonic.php)
- `wordsets/` - Mnemonic word lists for multiple languages

**Note**: `Class_MoneroScanner.php` creates its own instances of `Varint` and `ed25519` to avoid relying on non-public (protected) properties inside the vendored `Cryptonote` implementation.

## License

MIT

