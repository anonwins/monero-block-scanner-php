<?php
/**
 * Example usage of MoneroScanner class
 */
require_once 'Class_MoneroScanner.php';

// Sample config
$rpc_url = 'http://node.xmr.rocks:18089';
$socks5_proxy = '127.0.0.1:9050'; // Can be null
$private_view_key = 'YOUR_PRIVATE_VIEW_KEY_HEX_64_CHARS'; // <-- Replace this

// Define a callback that checks if a public spend key belongs to your wallet
// This mimics a bloom filter (or a database lookup): You just need to return true/false
$is_my_subaddress = function(string $public_spend_key): bool {
    // Minimal example: Array membership check
    $my_keys = [
        '5a3ab96c...7f610e6', // <-- Subaddress public spend key (64 chars)
        '0b46e7d1...18e5d2b', // <-- Another subaddress public spend key
    ];
    return in_array($public_spend_key, $my_keys, true);
};

// Initialize scanner
$scanner = new MoneroScanner('mainnet');

// Define which blocks to scan
$block_heights = [3408787]; // <-- Replace with real block heights

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
    $matches = $scanner->extract_transactions_to_me($block['transactions'], $private_view_key, $is_my_subaddress);

    // Display block results (brief)
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

// Display final results
echo "=== RESULTS ===\n\n";
if (count($all_matches) > 0) {
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
} else {
    echo "No transactions found.\n";
}
