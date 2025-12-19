<?php
/**
 * Example usage of MoneroScanner class
 */

require_once 'Class_MoneroScanner.php';

    // Minimal config (replace with your values)
    $rpc_url = 'http://node.xmr.rocks:18089';
$socks5_proxy = null; // e.g. '127.0.0.1:9050'
$private_view_key = 'YOUR_PRIVATE_VIEW_KEY_HEX_64_CHARS';

// Define a callback that checks if a public spend key belongs to our wallet
// This mimics a bloom filter - you just need to return true/false
$is_my_subaddress = function(string $public_spend_key): bool {
    // Minimal example: array membership check (replace with your keys or a bloom filter)
    static $my_keys = [
        'SUBADDRESS_PUBLIC_SPEND_KEY_HEX_64_CHARS', // 64-chars Subaddress Public Spend Key
        // ...
    ];
    return in_array($public_spend_key, $my_keys, true);
};

// Initialize scanner
$scanner = new MoneroScanner('mainnet');

    // Block heights to scan (replace with real block heights)
    $block_heights = [3408787];

echo "=== Monero Block Scanner PHP (Example) ===\n\n";

$all_matches = [];

foreach ($block_heights as $height) {
    echo "Fetching block $height...\n";
    
    // Fetch the block with all transactions
    $block = $scanner->get_block_by_height($height, $rpc_url, $socks5_proxy);
    
    if (isset($block['error'])) {
        echo "  ERROR: " . $block['error'] . "\n";
        continue;
    }
    
    echo "  Hash: " . $block['hash'] . "\n";
    echo "  Transactions: " . $block['tx_count'] . "\n";
    
    // Extract transactions belonging to us
    $matches = $scanner->extract_transactions_to_me(
        $block['transactions'],
        $private_view_key,
        $is_my_subaddress
    );
    
    if (count($matches) > 0) {
        echo "  Found " . count($matches) . " output(s) to our wallet!\n";
        foreach ($matches as $match) {
            $match['block_height'] = $height;
            $all_matches[] = $match;
        }
    } else {
        echo "  No matches.\n";
    }
    
    echo "\n";
}

// Summary
echo "=== RESULTS ===\n\n";

if (count($all_matches) === 0) {
    echo "No transactions found.\n";
} else {
    $total = '0';
    foreach ($all_matches as $idx => $output) {
        echo "Output " . ($idx + 1) . ":\n";
        echo "  Block:      " . $output['block_height'] . "\n";
        echo "  TX Hash:    " . $output['tx_hash'] . "\n";
        echo "  Index:      " . $output['output_index'] . "\n";
        echo "  Spend Key:  " . $output['public_spend_key'] . "\n";
        echo "  Amount:     " . $output['amount_xmr'] . " XMR\n";
        echo "  TX Version: " . $output['tx_version'] . "\n";
        echo "  Unlock Time:" . $output['unlock_time'] . "\n";
        echo "  RingCT Type:" . $output['rct_type'] . "\n";
        echo "  Coinbase:   " . ($output['is_coinbase'] ? 'Yes' : 'No') . "\n\n";
        $total = bcadd($total, $output['amount_xmr'], 12);
    }
    echo "TOTAL: $total XMR\n";
}

