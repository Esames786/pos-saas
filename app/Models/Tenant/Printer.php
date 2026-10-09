<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    protected $connection = 'tenant';

    /**
     * CATERING-SEND-TO-PRINTER-1 — "windows" qisam ka printer.
     *
     * Ye IP:port se nahi, agent ke PC par us ke WINDOWS NAAM se pehchana jata
     * hai. Office ka HP M127fn host-based hai — us ke 9100 par kaccha
     * PCL/PostScript bhejna bharosemand nahi; Windows ke spooler se sab theek
     * chhapta hai aur A4/A5 ka chunao driver khud sambhal leta hai.
     */
    public const TYPE_WINDOWS = 'windows';

    /**
     * Document printers POS ke receipt/KOT dropdowns me nazar nahi aate, aur
     * mapping wali screen par bhi nahi — mapping thermal/station ka kaam hai.
     * Yehi alag role us pehre ki buniyad hai.
     */
    public const ROLE_DOCUMENT = 'document';

    /**
     * PRINTER-FORM-PARITY-1 (9 Oct) — form ki fehristein YAHAN se aati hain.
     *
     * Pehle ye teen fehristein Blade me HAATH SE likhi hui thin. Jab DB ke enum
     * me `windows`, `document` aur `A5` daale gaye to form wahin ka wahin raha
     * — aur natija ye nikla:
     *
     *   Edit kholte hi `windows` printer "Browser" dikhne laga (kyunke us ki
     *   asli qeemat list me thi hi nahi, aur select pehle option par gir gaya),
     *   `document` "Receipt" ban gaya, aur A5 wala "58mm". Save dabate hi ye
     *   sirf dikhawa nahi raha — DB me WAQAI likha gaya. Dono document
     *   printers thermal receipt printers ban gaye aur "Send to network" teenon
     *   safhon se gayab ho gaya.
     *
     * Us din ka sabaq: ek chunao jis me mojooda qeemat shaamil na ho, wo
     * khamoshi se data badal deta hai. Ab fehrist ek hi jagah hai, aur
     * `PrinterFormParityMySqlTest` DB ke enum se mila kar dekhta hai.
     */
    public const TYPES = [
        'browser' => 'Browser (print dialog)',
        'network' => 'Network (IP/Port)',
        'usb' => 'USB',
        self::TYPE_WINDOWS => 'Windows printer (A4/A5 documents)',
    ];

    public const ROLES = [
        'receipt' => 'Receipt',
        'kot' => 'KOT',
        'both' => 'Both',
        self::ROLE_DOCUMENT => 'Document (A4/A5)',
    ];

    public const PAPER_SIZES = ['58mm', '80mm', 'A4', 'A5'];

    protected $fillable = [
        'branch_id', 'name', 'code', 'printer_type', 'print_role', 'supports_reminder',
        'ip_address', 'port', 'windows_printer_name', 'paper_size', 'characters_per_line',
        'is_default', 'is_active', 'agent_enabled', 'last_seen_at', 'last_error', 'notes',
        'last_ping_ok', 'last_ping_ms', 'last_ping_at',
    ];

    /** Wo printers jo A4/A5 document chhap sakte hain. */
    public function scopeDocumentCapable($query)
    {
        return $query->where('is_active', true)
            ->where('printer_type', self::TYPE_WINDOWS);
    }

    /**
     * Thermal/station wala purana raasta. Jaan-boojh kar `document` ko BAHAR
     * rakhta hai: ek A4 laser par ESC/POS bytes bhej dena safhe bhar kachra
     * chhapta hai.
     */
    public function scopeThermal($query)
    {
        return $query->where('printer_type', '!=', self::TYPE_WINDOWS);
    }

    protected function casts(): array
    {
        return [
            'is_default'    => 'boolean',
            'is_active'     => 'boolean',
            'supports_reminder' => 'boolean',
            'agent_enabled' => 'boolean',
            'last_seen_at'  => 'datetime',
            'port'          => 'integer',
            'characters_per_line' => 'integer',
            'last_ping_ok'  => 'boolean',
            'last_ping_ms'  => 'integer',
            'last_ping_at'  => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function printJobs()
    {
        return $this->hasMany(PrintJob::class);
    }

    public function categoryMappings()
    {
        return $this->hasMany(CategoryPrinterMapping::class);
    }
}
