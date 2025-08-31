<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transcript extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'title',
        'description',
        'image',
        'transcript',
        'status',
        'error_message',
        'processed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The possible status values for the transcript.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Get all possible status values.
     *
     * @return array<string>
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Scope a query to only include transcripts with a specific status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include completed transcripts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed transcripts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include pending transcripts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include processing transcripts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Check if the transcript is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the transcript is failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the transcript is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the transcript is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Mark the transcript as processing.
     *
     * @return bool
     */
    public function markAsProcessing(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark the transcript as completed with transcript data.
     *
     * @param array $transcriptData
     * @return bool
     */
    public function markAsCompleted(array $transcriptData): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'transcript' => $transcriptData,
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark the transcript as failed with error message.
     *
     * @param string $errorMessage
     * @return bool
     */
    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Reset the transcript status to pending for retry.
     *
     * @return bool
     */
    public function resetToPending(): bool
    {
        return $this->update([
            'status' => self::STATUS_PENDING,
            'transcript' => null,
            'error_message' => null,
            'processed_at' => null,
        ]);
    }

    /**
     * Validate the transcript JSON structure against the expected schema.
     *
     * @param array $transcriptData
     * @return bool
     */
    public static function validateTranscriptSchema(array $transcriptData): bool
    {
        // Check for required top-level keys
        $requiredKeys = ['patient', 'date', 'prescriptions', 'diagnoses', 'observations', 'tests', 'instructions', 'doctor'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $transcriptData)) {
                return false;
            }
        }

        // Validate patient object
        if (!is_array($transcriptData['patient']) ||
            !isset($transcriptData['patient']['name']) ||
            !isset($transcriptData['patient']['age']) ||
            !isset($transcriptData['patient']['gender'])) {
            return false;
        }

        // Validate doctor object
        if (!is_array($transcriptData['doctor']) ||
            !isset($transcriptData['doctor']['name']) ||
            !isset($transcriptData['doctor']['signature'])) {
            return false;
        }

        // Validate arrays
        foreach (['prescriptions', 'diagnoses', 'observations', 'tests'] as $arrayKey) {
            if (!is_array($transcriptData[$arrayKey])) {
                return false;
            }
        }

        // Validate prescription structure
        foreach ($transcriptData['prescriptions'] as $prescription) {
            if (!is_array($prescription) ||
                !isset($prescription['drug_name']) ||
                !isset($prescription['dosage']) ||
                !isset($prescription['route']) ||
                !isset($prescription['frequency']) ||
                !isset($prescription['duration'])) {
                return false;
            }
        }

        // Validate diagnoses structure
        foreach ($transcriptData['diagnoses'] as $diagnosis) {
            if (!is_array($diagnosis) || !isset($diagnosis['condition'])) {
                return false;
            }
        }

        // Validate tests structure
        foreach ($transcriptData['tests'] as $test) {
            if (!is_array($test) || !isset($test['test_name'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a formatted display of the transcript data.
     *
     * @return array|null
     */
    public function getFormattedTranscript(): ?array
    {
        if (!$this->transcript || !$this->isCompleted()) {
            return null;
        }

        return $this->transcript;
    }
}
