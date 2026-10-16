<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 — platform ki jagah (society / mohalla) aur us ka area. OpenStreetMap se ya hamari chuni hui.
 * Restaurant ise apne liye chhupa / doosre area me dal sakta hai (Tenant\DeliveryZonePlace, source "override").
 */
class GeoAreaPlace extends Model
{
    public const SOURCE_OSM = 'osm';

    public const SOURCE_PLATFORM = 'platform';

    public const KIND_SURE = 'sure';

    public const KIND_NEARBY = 'nearby';

    protected $connection = 'master';

    protected $table = 'geo_area_places';

    protected $fillable = ['geo_area_id', 'city', 'name', 'name_key', 'lat', 'lng', 'source', 'kind', 'osm_ref', 'status'];

    protected function casts(): array
    {
        return ['geo_area_id' => 'integer', 'lat' => 'float', 'lng' => 'float'];
    }

    public function geoArea(): BelongsTo
    {
        return $this->belongsTo(GeoArea::class);
    }
}
