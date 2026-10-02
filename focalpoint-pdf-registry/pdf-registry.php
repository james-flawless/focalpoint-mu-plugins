<?php

if (!defined('ABSPATH')) {
    exit;
}

function fp_pdf_registry_allowed_categories() {
    return ['aspirations', 'coaching', 'feedback'];
}

function fp_pdf_registry_storage_dir() {
    return apply_filters(
        'fp_pdf_registry_storage_dir',
        trailingslashit(WP_CONTENT_DIR) . 'uploads/sites/4/pdf_data'
    );
}

function fp_pdf_registry_file_path() {
    return trailingslashit(fp_pdf_registry_storage_dir()) . 'PDF_uploads.json';
}

function fp_pdf_registry_empty_document() {
    return [
        'schema_version' => 1,
        'updated_at'     => null,
        'records'        => [],
    ];
}

function fp_pdf_registry_now() {
    return gmdate('c');
}

function fp_pdf_registry_normalize_timestamp($timestamp) {
    $timestamp = trim((string) $timestamp);
    if ($timestamp === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp, wp_timezone());
    if ($date instanceof DateTimeImmutable) {
        return $date->format(DATE_ATOM);
    }

    try {
        return (new DateTimeImmutable($timestamp))->format(DATE_ATOM);
    } catch (Throwable $e) {
        return $timestamp;
    }
}

function fp_pdf_registry_source_name($site_id) {
    $site_id = intval($site_id);

    if ($site_id === 2) {
        return 'ous';
    }

    if ($site_id === 3) {
        return 'usa';
    }

    if ($site_id === 4) {
        return 'management';
    }

    return 'unknown';
}

function fp_pdf_registry_record_id($site_id, $username, $category, $filename) {
    $identity = implode('|', [
        intval($site_id),
        (string) $username,
        strtolower((string) $category),
        (string) $filename,
    ]);

    return 'pdf_' . substr(hash('sha256', $identity), 0, 16);
}

function fp_pdf_registry_relative_path($site_id, $username, $category, $filename) {
    return implode('/', [
        'sites',
        intval($site_id),
        trim((string) $username, '/'),
        '__user_pdfs__',
        strtolower((string) $category),
        basename((string) $filename),
    ]);
}

function fp_pdf_registry_validate_identity($site_id, $username, $category, $filename) {
    $site_id = intval($site_id);
    $category = strtolower((string) $category);
    $filename = (string) $filename;

    if (!in_array($site_id, [2, 3], true)) {
        return new WP_Error('fp_pdf_registry_invalid_site', 'The PDF recipient site must be OUS (2) or USA (3).');
    }

    if ($username === '' || strpos((string) $username, '/') !== false || strpos((string) $username, "\\") !== false) {
        return new WP_Error('fp_pdf_registry_invalid_username', 'The PDF recipient username is invalid.');
    }

    if (!in_array($category, fp_pdf_registry_allowed_categories(), true)) {
        return new WP_Error('fp_pdf_registry_invalid_category', 'The PDF category is invalid.');
    }

    if ($filename === '' || basename($filename) !== $filename || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
        return new WP_Error('fp_pdf_registry_invalid_filename', 'The PDF filename is invalid.');
    }

    return true;
}

function fp_pdf_registry_normalize_document($decoded) {
    if (!is_array($decoded)) {
        return new WP_Error('fp_pdf_registry_invalid_json', 'The PDF registry does not contain a valid JSON object.');
    }

    if (isset($decoded['records']) && is_array($decoded['records'])) {
        $decoded['schema_version'] = intval($decoded['schema_version'] ?? 1);
        $decoded['updated_at'] = $decoded['updated_at'] ?? null;
        $decoded['records'] = array_values($decoded['records']);
        return $decoded;
    }

    // Allow an early bare-list registry to be upgraded without losing its records.
    $is_list = empty($decoded) || array_keys($decoded) === range(0, count($decoded) - 1);
    if ($is_list) {
        return [
            'schema_version' => 1,
            'updated_at'     => null,
            'records'        => array_values($decoded),
        ];
    }

    return new WP_Error('fp_pdf_registry_invalid_shape', 'The PDF registry JSON structure is not recognised.');
}

function fp_pdf_registry_read() {
    $path = fp_pdf_registry_file_path();

    if (!is_file($path)) {
        return fp_pdf_registry_empty_document();
    }

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return new WP_Error('fp_pdf_registry_open_failed', 'The PDF registry could not be opened for reading.');
    }

    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        return new WP_Error('fp_pdf_registry_lock_failed', 'The PDF registry could not be locked for reading.');
    }

    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($raw === false || trim($raw) === '') {
        return fp_pdf_registry_empty_document();
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('fp_pdf_registry_invalid_json', 'The PDF registry contains invalid JSON: ' . json_last_error_msg());
    }

    return fp_pdf_registry_normalize_document($decoded);
}

function fp_pdf_registry_mutate($callback, $create_backup = false) {
    $directory = fp_pdf_registry_storage_dir();
    if (!is_dir($directory) && !wp_mkdir_p($directory)) {
        return new WP_Error('fp_pdf_registry_directory_failed', 'The Site 4 PDF registry directory could not be created.');
    }

    $path = fp_pdf_registry_file_path();
    $handle = fopen($path, 'c+');
    if (!$handle) {
        return new WP_Error('fp_pdf_registry_open_failed', 'The PDF registry could not be opened for writing.');
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return new WP_Error('fp_pdf_registry_lock_failed', 'The PDF registry could not be locked for writing.');
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    if ($raw === false || trim($raw) === '') {
        $document = fp_pdf_registry_empty_document();
    } else {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return new WP_Error('fp_pdf_registry_invalid_json', 'The existing PDF registry contains invalid JSON and was not overwritten.');
        }

        $document = fp_pdf_registry_normalize_document($decoded);
        if (is_wp_error($document)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return $document;
        }
    }

    $callback_result = call_user_func_array($callback, [&$document]);
    if (is_wp_error($callback_result)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return $callback_result;
    }

    $document['schema_version'] = 1;
    $document['updated_at'] = fp_pdf_registry_now();
    $document['records'] = array_values($document['records'] ?? []);

    $encoded = wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return new WP_Error('fp_pdf_registry_encode_failed', 'The PDF registry could not be encoded.');
    }

    $backup_path = null;
    if ($create_backup && trim((string) $raw) !== '') {
        $backup_name = wp_unique_filename($directory, 'PDF_uploads__backup__' . gmdate('Ymd_His') . '.json');
        $backup_path = trailingslashit($directory) . $backup_name;
        if (file_put_contents($backup_path, $raw, LOCK_EX) === false) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return new WP_Error('fp_pdf_registry_backup_failed', 'The PDF registry backup could not be created.');
        }
    }

    rewind($handle);
    if (!ftruncate($handle, 0)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return new WP_Error('fp_pdf_registry_truncate_failed', 'The PDF registry could not be prepared for writing.');
    }

    $written = fwrite($handle, $encoded . PHP_EOL);
    if ($written === false || !fflush($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return new WP_Error('fp_pdf_registry_write_failed', 'The PDF registry could not be written.');
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return [
        'result'      => $callback_result,
        'file_path'   => $path,
        'backup_path' => $backup_path,
        'record_count'=> count($document['records']),
    ];
}

function fp_pdf_registry_find_record_index($records, $record_id) {
    foreach ($records as $index => $record) {
        if (($record['id'] ?? '') === $record_id) {
            return $index;
        }
    }

    return -1;
}

function fp_pdf_registry_record_upload($args) {
    $defaults = [
        'recipient_site_id'      => 0,
        'recipient_user_id'      => 0,
        'recipient_username'     => '',
        'recipient_display_name' => '',
        'category'               => '',
        'original_filename'      => '',
        'stored_filename'        => '',
        'pdf_path'               => '',
        'cover_path'             => '',
        'upload_source_site_id'  => 0,
        'upload_source'          => '',
        'upload_interface'       => '',
        'uploaded_by_user_id'    => 0,
        'uploaded_by_username'   => '',
        'uploaded_by_display_name' => '',
    ];
    $args = wp_parse_args($args, $defaults);

    $valid = fp_pdf_registry_validate_identity(
        $args['recipient_site_id'],
        $args['recipient_username'],
        $args['category'],
        $args['stored_filename']
    );
    if (is_wp_error($valid)) {
        return $valid;
    }

    $site_id = intval($args['recipient_site_id']);
    $username = sanitize_text_field((string) $args['recipient_username']);
    $category = strtolower((string) $args['category']);
    $filename = basename((string) $args['stored_filename']);
    $record_id = fp_pdf_registry_record_id($site_id, $username, $category, $filename);
    $now = fp_pdf_registry_now();
    $pdf_path = (string) $args['pdf_path'];
    $cover_path = (string) $args['cover_path'];

    $record = [
        'id'                       => $record_id,
        'recipient_site_id'        => $site_id,
        'recipient_user_id'        => intval($args['recipient_user_id']),
        'recipient_username'       => $username,
        'recipient_display_name'   => sanitize_text_field((string) $args['recipient_display_name']),
        'category'                 => $category,
        'original_filename'        => sanitize_text_field((string) $args['original_filename']),
        'stored_filename'          => $filename,
        'relative_path'            => fp_pdf_registry_relative_path($site_id, $username, $category, $filename),
        'cover_relative_path'      => ($cover_path && is_file($cover_path))
            ? fp_pdf_registry_relative_path($site_id, $username, $category, basename($cover_path))
            : null,
        'file_size'                => ($pdf_path && is_file($pdf_path)) ? intval(filesize($pdf_path)) : null,
        'uploaded_at'              => $now,
        'uploaded_by_user_id'      => intval($args['uploaded_by_user_id']),
        'uploaded_by_username'     => sanitize_text_field((string) $args['uploaded_by_username']),
        'uploaded_by_display_name' => sanitize_text_field((string) $args['uploaded_by_display_name']),
        'upload_source_site_id'    => intval($args['upload_source_site_id']),
        'upload_source'            => sanitize_key($args['upload_source'] ?: fp_pdf_registry_source_name($args['upload_source_site_id'])),
        'upload_interface'         => sanitize_key((string) $args['upload_interface']),
        'record_source'            => 'live_upload',
        'status'                   => 'active',
        'read'                     => false,
        'first_opened_at'          => null,
        'last_opened_at'           => null,
        'open_count'               => 0,
        'historical_open_count_unknown' => false,
        'deleted_at'               => null,
        'deleted_by_user_id'       => null,
        'deleted_by_username'      => null,
        'missing_detected_at'      => null,
    ];

    return fp_pdf_registry_mutate(function (&$document) use ($record, $record_id) {
        $index = fp_pdf_registry_find_record_index($document['records'], $record_id);
        if ($index >= 0) {
            $record = array_merge($document['records'][$index], $record);
            $document['records'][$index] = $record;
        } else {
            $document['records'][] = $record;
        }

        return ['record_id' => $record_id, 'created' => $index < 0];
    });
}

function fp_pdf_registry_mark_opened($args) {
    $defaults = [
        'recipient_site_id'      => 0,
        'recipient_user_id'      => 0,
        'recipient_username'     => '',
        'recipient_display_name' => '',
        'category'               => '',
        'stored_filename'        => '',
        'pdf_path'               => '',
        'cover_path'             => '',
        'known_first_opened_at'  => '',
    ];
    $args = wp_parse_args($args, $defaults);

    $valid = fp_pdf_registry_validate_identity(
        $args['recipient_site_id'],
        $args['recipient_username'],
        $args['category'],
        $args['stored_filename']
    );
    if (is_wp_error($valid)) {
        return $valid;
    }

    $site_id = intval($args['recipient_site_id']);
    $username = sanitize_text_field((string) $args['recipient_username']);
    $category = strtolower((string) $args['category']);
    $filename = basename((string) $args['stored_filename']);
    $record_id = fp_pdf_registry_record_id($site_id, $username, $category, $filename);
    $now = fp_pdf_registry_now();

    return fp_pdf_registry_mutate(function (&$document) use ($args, $site_id, $username, $category, $filename, $record_id, $now) {
        $index = fp_pdf_registry_find_record_index($document['records'], $record_id);

        if ($index < 0) {
            $cover_path = (string) $args['cover_path'];
            $pdf_path = (string) $args['pdf_path'];
            $first_opened_at = fp_pdf_registry_normalize_timestamp($args['known_first_opened_at']) ?: $now;
            $document['records'][] = [
                'id'                       => $record_id,
                'recipient_site_id'        => $site_id,
                'recipient_user_id'        => intval($args['recipient_user_id']),
                'recipient_username'       => $username,
                'recipient_display_name'   => sanitize_text_field((string) $args['recipient_display_name']),
                'category'                 => $category,
                'original_filename'        => null,
                'stored_filename'          => $filename,
                'relative_path'            => fp_pdf_registry_relative_path($site_id, $username, $category, $filename),
                'cover_relative_path'      => ($cover_path && is_file($cover_path))
                    ? fp_pdf_registry_relative_path($site_id, $username, $category, basename($cover_path))
                    : null,
                'file_size'                => ($pdf_path && is_file($pdf_path)) ? intval(filesize($pdf_path)) : null,
                'uploaded_at'              => fp_pdf_registry_uploaded_at_from_file($filename, $pdf_path),
                'uploaded_by_user_id'      => null,
                'uploaded_by_username'     => null,
                'uploaded_by_display_name' => null,
                'upload_source_site_id'    => null,
                'upload_source'            => 'unknown',
                'upload_interface'         => 'open_backfill',
                'record_source'            => 'open_backfill',
                'status'                   => 'active',
                'read'                     => true,
                'first_opened_at'          => $first_opened_at,
                'last_opened_at'           => $now,
                'open_count'               => 1,
                'historical_open_count_unknown' => !empty($args['known_first_opened_at']),
                'deleted_at'               => null,
                'deleted_by_user_id'       => null,
                'deleted_by_username'      => null,
                'missing_detected_at'      => null,
            ];
        } else {
            $record = $document['records'][$index];
            $record['read'] = true;
            if (empty($record['first_opened_at'])) {
                $record['first_opened_at'] = fp_pdf_registry_normalize_timestamp($args['known_first_opened_at']) ?: $now;
            }
            $record['last_opened_at'] = $now;
            $record['open_count'] = intval($record['open_count'] ?? 0) + 1;
            $record['status'] = 'active';
            $record['missing_detected_at'] = null;
            $document['records'][$index] = $record;
        }

        return ['record_id' => $record_id, 'created' => $index < 0];
    });
}

function fp_pdf_registry_mark_deleted($args) {
    $defaults = [
        'recipient_site_id'   => 0,
        'recipient_username'  => '',
        'category'            => '',
        'stored_filename'     => '',
        'deleted_by_user_id'  => 0,
        'deleted_by_username' => '',
        'delete_source_site_id' => 0,
        'delete_interface'    => '',
    ];
    $args = wp_parse_args($args, $defaults);

    $valid = fp_pdf_registry_validate_identity(
        $args['recipient_site_id'],
        $args['recipient_username'],
        $args['category'],
        $args['stored_filename']
    );
    if (is_wp_error($valid)) {
        return $valid;
    }

    $record_id = fp_pdf_registry_record_id(
        $args['recipient_site_id'],
        $args['recipient_username'],
        $args['category'],
        $args['stored_filename']
    );
    $now = fp_pdf_registry_now();

    return fp_pdf_registry_mutate(function (&$document) use ($args, $record_id, $now) {
        $index = fp_pdf_registry_find_record_index($document['records'], $record_id);
        if ($index < 0) {
            $site_id = intval($args['recipient_site_id']);
            $username = sanitize_text_field((string) $args['recipient_username']);
            $category = strtolower((string) $args['category']);
            $filename = basename((string) $args['stored_filename']);
            $record = [
                'id'                       => $record_id,
                'recipient_site_id'        => $site_id,
                'recipient_user_id'        => 0,
                'recipient_username'       => $username,
                'recipient_display_name'   => '',
                'category'                 => $category,
                'original_filename'        => null,
                'stored_filename'          => $filename,
                'relative_path'            => fp_pdf_registry_relative_path($site_id, $username, $category, $filename),
                'cover_relative_path'      => null,
                'file_size'                => null,
                'uploaded_at'              => fp_pdf_registry_uploaded_at_from_file($filename),
                'uploaded_by_user_id'      => null,
                'uploaded_by_username'     => null,
                'uploaded_by_display_name' => null,
                'upload_source_site_id'    => null,
                'upload_source'            => 'unknown',
                'upload_interface'         => 'unknown',
                'record_source'            => 'delete_backfill',
                'read'                     => false,
                'first_opened_at'          => null,
                'last_opened_at'           => null,
                'open_count'               => null,
                'historical_open_count_unknown' => true,
            ];
            $document['records'][] = $record;
            $index = count($document['records']) - 1;
        }

        $record = $document['records'][$index];
        $record['status'] = 'deleted';
        $record['deleted_at'] = $now;
        $record['deleted_by_user_id'] = intval($args['deleted_by_user_id']);
        $record['deleted_by_username'] = sanitize_text_field((string) $args['deleted_by_username']);
        $record['delete_source_site_id'] = intval($args['delete_source_site_id']);
        $record['delete_interface'] = sanitize_key((string) $args['delete_interface']);
        $record['missing_detected_at'] = null;
        $document['records'][$index] = $record;

        return ['record_id' => $record_id];
    });
}

function fp_pdf_registry_uploaded_at_from_file($filename, $path = '') {
    if (preg_match('/__(\d{8}_\d{6})(?:-\d+)?\.pdf$/i', (string) $filename, $matches)) {
        $date = DateTimeImmutable::createFromFormat('!Ymd_His', $matches[1], wp_timezone());
        if ($date instanceof DateTimeImmutable) {
            return $date->format(DATE_ATOM);
        }
    }

    if ($path && is_file($path)) {
        return gmdate('c', filemtime($path));
    }

    return null;
}

function fp_pdf_registry_scan_existing($site_ids = [2, 3]) {
    $site_ids = array_values(array_intersect(array_map('intval', (array) $site_ids), [2, 3]));
    $records = [];
    $stats = [
        'sites_scanned'       => $site_ids,
        'pdfs_found'          => 0,
        'known_opened'        => 0,
        'missing_covers'      => 0,
        'unmatched_usernames' => [],
        'invalid_files'       => [],
    ];

    foreach ($site_ids as $site_id) {
        $site_base = trailingslashit(WP_CONTENT_DIR) . 'uploads/sites/' . $site_id;
        if (!is_dir($site_base)) {
            continue;
        }

        switch_to_blog($site_id);
        try {
            $user_directories = new DirectoryIterator($site_base);
            foreach ($user_directories as $user_directory) {
                if ($user_directory->isDot() || !$user_directory->isDir()) {
                    continue;
                }

                $username = $user_directory->getFilename();
                $pdf_base = $user_directory->getPathname() . '/__user_pdfs__';
                if (!is_dir($pdf_base)) {
                    continue;
                }

                $user = get_user_by('login', $username);
                if (!$user) {
                    $stats['unmatched_usernames'][$site_id . ':' . $username] = [
                        'site_id'  => $site_id,
                        'username' => $username,
                    ];
                }

                $read_items = $user
                    ? get_user_meta($user->ID, 'fp_user_pdf_read_items_site_' . $site_id, true)
                    : [];
                $read_items = is_array($read_items) ? $read_items : [];

                foreach (fp_pdf_registry_allowed_categories() as $category) {
                    $category_dir = $pdf_base . '/' . $category;
                    if (!is_dir($category_dir)) {
                        continue;
                    }

                    $files = new DirectoryIterator($category_dir);
                    foreach ($files as $file) {
                        if (!$file->isFile() || strtolower($file->getExtension()) !== 'pdf') {
                            continue;
                        }

                        $filename = $file->getFilename();
                        if (!preg_match('/^(.*?)__(\d{8}_\d{6})(?:-\d+)?\.pdf$/i', $filename)) {
                            $stats['invalid_files'][] = $file->getPathname();
                            continue;
                        }

                        $pdf_id = $category . '/' . $filename;
                        $known_read_at = isset($read_items[$pdf_id]) ? (string) $read_items[$pdf_id] : null;
                        $cover_filename = preg_replace('/\.pdf$/i', '__cover.webp', $filename);
                        $cover_path = $category_dir . '/' . $cover_filename;
                        $record_id = fp_pdf_registry_record_id($site_id, $username, $category, $filename);

                        $records[$record_id] = [
                            'id'                       => $record_id,
                            'recipient_site_id'        => $site_id,
                            'recipient_user_id'        => $user ? intval($user->ID) : 0,
                            'recipient_username'       => $username,
                            'recipient_display_name'   => $user ? sanitize_text_field($user->display_name) : '',
                            'category'                 => $category,
                            'original_filename'        => null,
                            'stored_filename'          => $filename,
                            'relative_path'            => fp_pdf_registry_relative_path($site_id, $username, $category, $filename),
                            'cover_relative_path'      => is_file($cover_path)
                                ? fp_pdf_registry_relative_path($site_id, $username, $category, $cover_filename)
                                : null,
                            'file_size'                => intval($file->getSize()),
                            'uploaded_at'              => fp_pdf_registry_uploaded_at_from_file($filename, $file->getPathname()),
                            'uploaded_by_user_id'      => null,
                            'uploaded_by_username'     => null,
                            'uploaded_by_display_name' => null,
                            'upload_source_site_id'    => null,
                            'upload_source'            => 'unknown',
                            'upload_interface'         => 'migration',
                            'record_source'            => 'filesystem_migration',
                            'status'                   => 'active',
                            'read'                     => $known_read_at !== null,
                            'first_opened_at'          => fp_pdf_registry_normalize_timestamp($known_read_at),
                            'last_opened_at'           => fp_pdf_registry_normalize_timestamp($known_read_at),
                            'open_count'               => $known_read_at !== null ? null : 0,
                            'historical_open_count_unknown' => $known_read_at !== null,
                            'deleted_at'               => null,
                            'deleted_by_user_id'       => null,
                            'deleted_by_username'      => null,
                            'missing_detected_at'      => null,
                        ];

                        $stats['pdfs_found']++;
                        if ($known_read_at !== null) {
                            $stats['known_opened']++;
                        }
                        if (!is_file($cover_path)) {
                            $stats['missing_covers']++;
                        }
                    }
                }
            }
        } finally {
            restore_current_blog();
        }
    }

    $stats['unmatched_usernames'] = array_values($stats['unmatched_usernames']);

    return ['records' => $records, 'stats' => $stats];
}

function fp_pdf_registry_rebuild($args = []) {
    $args = wp_parse_args($args, [
        'site_ids' => [2, 3],
        'dry_run'  => true,
        'mode'     => 'reconcile',
    ]);
    $mode = in_array($args['mode'], ['initial_migration', 'reconcile'], true)
        ? $args['mode']
        : 'reconcile';
    $dry_run = (bool) $args['dry_run'];
    $scan = fp_pdf_registry_scan_existing($args['site_ids']);
    $scanned_records = $scan['records'];
    $stats = $scan['stats'];
    $stats['mode'] = $mode;
    $stats['dry_run'] = $dry_run;
    $stats['records_created'] = 0;
    $stats['records_updated'] = 0;
    $stats['records_marked_missing'] = 0;

    $current = fp_pdf_registry_read();
    if (is_wp_error($current)) {
        return $current;
    }

    $current_ids = [];
    foreach ($current['records'] as $record) {
        if (!empty($record['id'])) {
            $current_ids[$record['id']] = true;
        }
    }
    foreach ($scanned_records as $record_id => $unused) {
        if (isset($current_ids[$record_id])) {
            $stats['records_updated']++;
        } else {
            $stats['records_created']++;
        }
    }

    if ($dry_run) {
        return $stats;
    }

    $site_ids = array_values(array_intersect(array_map('intval', (array) $args['site_ids']), [2, 3]));
    $write = fp_pdf_registry_mutate(function (&$document) use ($scanned_records, $site_ids, $mode, &$stats) {
        $record_indexes = [];
        foreach ($document['records'] as $index => $record) {
            if (!empty($record['id'])) {
                $record_indexes[$record['id']] = $index;
            }
        }

        foreach ($scanned_records as $record_id => $scanned) {
            if (isset($record_indexes[$record_id])) {
                $index = $record_indexes[$record_id];
                $existing = $document['records'][$index];
                $merged = array_merge($scanned, $existing);

                foreach ([
                    'recipient_site_id', 'recipient_user_id', 'recipient_username', 'recipient_display_name',
                    'category', 'stored_filename', 'relative_path', 'cover_relative_path', 'file_size', 'uploaded_at',
                ] as $filesystem_key) {
                    $merged[$filesystem_key] = $scanned[$filesystem_key];
                }

                if (!empty($scanned['read']) && empty($existing['read'])) {
                    $merged['read'] = true;
                    $merged['first_opened_at'] = $scanned['first_opened_at'];
                    $merged['last_opened_at'] = $scanned['last_opened_at'];
                    $merged['open_count'] = null;
                    $merged['historical_open_count_unknown'] = true;
                }

                $merged['status'] = 'active';
                $merged['missing_detected_at'] = null;
                $document['records'][$index] = $merged;
            } else {
                $document['records'][] = $scanned;
                $record_indexes[$record_id] = count($document['records']) - 1;
            }
        }

        if ($mode === 'reconcile') {
            $missing_time = fp_pdf_registry_now();
            foreach ($document['records'] as $index => $record) {
                $record_site_id = intval($record['recipient_site_id'] ?? 0);
                $record_id = $record['id'] ?? '';
                if (
                    in_array($record_site_id, $site_ids, true)
                    && ($record['status'] ?? 'active') === 'active'
                    && $record_id !== ''
                    && !isset($scanned_records[$record_id])
                ) {
                    $document['records'][$index]['status'] = 'missing';
                    $document['records'][$index]['missing_detected_at'] = $missing_time;
                    $stats['records_marked_missing']++;
                }
            }
        }

        usort($document['records'], function ($a, $b) {
            return strcmp((string) ($b['uploaded_at'] ?? ''), (string) ($a['uploaded_at'] ?? ''));
        });

        return $stats;
    }, true);

    if (is_wp_error($write)) {
        return $write;
    }

    $stats['file_path'] = $write['file_path'];
    $stats['backup_path'] = $write['backup_path'];
    $stats['total_registry_records'] = $write['record_count'];

    return $stats;
}
