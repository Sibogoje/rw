<?php
/**
 * Standard JSON response helper for the API.
 *
 * Supports an optional 4th parameter ($meta) which many endpoints pass as
 * json_response('success', $data, null, ['meta' => [ ... ]]);
 */
function json_response($status, $data = null, $message = null, $meta = null) {
    header('Content-Type: application/json');
    $out = [];
    if ($status === 'success') {
        $out['status'] = 'success';
        $out['data'] = $data;
        if ($meta !== null) {
            // Allow callers to pass either the meta array directly or wrap it in ['meta' => ...]
            if (is_array($meta) && array_key_exists('meta', $meta)) {
                $out['meta'] = $meta['meta'];
            } else {
                $out['meta'] = $meta;
            }
        }
    } else {
        $out['status'] = 'error';
        $out['message'] = $message;
    }
    echo json_encode($out);
    exit;
}
?>