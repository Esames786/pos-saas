<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 — one immutable section of a bootstrap snapshot. The payload
 * is stored gzip-compressed; the logical content_hash is over the UNCOMPRESSED canonical JSON.
 */
class EdgeBootstrapSnapshotSection extends Model
{
    protected $connection = 'master';

    protected $fillable = ['snapshot_id', 'name', 'content_hash', 'row_count', 'payload_gz'];

    /** The canonical JSON string (decompressed) — identical bytes on every read (immutable). */
    public function json(): string
    {
        return (string) gzdecode(base64_decode($this->payload_gz));
    }
}
