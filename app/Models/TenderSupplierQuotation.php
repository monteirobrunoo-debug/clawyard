<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderSupplierQuotation extends Model
{
    protected $fillable = [
        'tender_id', 'supplier_id', 'supplier_name_freetext',
        'unit_price', 'currency', 'quantity', 'total_price',
        'delivery_days', 'validity_days', 'incoterm', 'notes',
        'pdf_attachment_id', 'parsed_by_marta_at', 'created_by_user_id',
    ];

    protected $casts = [
        'unit_price'          => 'decimal:2',
        'total_price'         => 'decimal:2',
        'quantity'            => 'integer',
        'delivery_days'       => 'integer',
        'validity_days'       => 'integer',
        'parsed_by_marta_at'  => 'datetime',
    ];

    public function tender(): BelongsTo     { return $this->belongsTo(Tender::class); }
    public function supplier(): BelongsTo   { return $this->belongsTo(Supplier::class); }
    public function attachment(): BelongsTo { return $this->belongsTo(TenderAttachment::class, 'pdf_attachment_id'); }
    public function createdBy(): BelongsTo  { return $this->belongsTo(User::class, 'created_by_user_id'); }

    /** Nome do fornecedor (formal ou ad-hoc). */
    public function supplierName(): string
    {
        return $this->supplier?->name
            ?? (string) ($this->supplier_name_freetext ?? '(sem nome)');
    }

    /** Total computado quando não está em DB (qty × unit_price). */
    public function effectiveTotal(): ?float
    {
        if ($this->total_price !== null) return (float) $this->total_price;
        if ($this->unit_price !== null) {
            return round((float) $this->unit_price * max(1, (int) $this->quantity), 2);
        }
        return null;
    }
}
