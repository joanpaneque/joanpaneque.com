<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class InstagramDirectMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $table = 'instagram_direct_messages';

    protected $fillable = [
        'peer_ig_user_id',
        'direction',
        'body',
        'meta_message_id',
    ];

    /**
     * @return Collection<int, InstagramDirectMessage>
     */
    public static function recentForPeer(string $peerIgUserId, int $limit = 7): Collection
    {
        return static::query()
            ->where('peer_ig_user_id', $peerIgUserId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();
    }
}
