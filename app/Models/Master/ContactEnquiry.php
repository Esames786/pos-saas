<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * CONTACT-FORM-1 — one enquiry from the public "Contact Sales" form.
 *
 * Master ki table hai, kisi tenant ki nahi: likhne wala abhi koi customer nahi, ek ajnabi hai jo
 * website par aaya.
 */
class ContactEnquiry extends Model
{
    protected $connection = 'master';

    protected $table = 'contact_enquiries';

    protected $fillable = [
        'name', 'email', 'phone', 'business_name', 'topic', 'message',
        'locale', 'plan_hint', 'ip_address', 'user_agent',
        'status', 'handled_at', 'handled_by', 'notified_at',
    ];

    protected $casts = [
        'handled_at'  => 'datetime',
        'notified_at' => 'datetime',
    ];

    public const STATUS_NEW = 'new';

    public const STATUS_HANDLED = 'handled';

    /** Jo mauzu safhe par cards ki shakl me hain — form ka select inhi me se chunta hai. */
    public const TOPICS = [
        'demo'       => 'Book a product demo',
        'enterprise' => 'Enterprise / multi-branch rollout',
        'migration'  => 'Data migration',
        'training'   => 'Training & onboarding',
        'custom'     => 'Custom plan / pricing',
        'fbr'        => 'FBR setup discussion',
        'other'      => 'Something else',
    ];

    public function topicLabel(): string
    {
        return self::TOPICS[$this->topic] ?? (string) ($this->topic ?: '—');
    }

    public function isHandled(): bool
    {
        return $this->status === self::STATUS_HANDLED;
    }
}
