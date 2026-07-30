<?php

namespace App\Models;

use App\Notifications\FactoryWebPushNotification;
use App\Support\Push\PushCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasPushSubscriptions;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'status' => 'boolean',
    ];

    protected $appends = [
        'profile_photo_url',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return (bool) $this->status;
    }

    /*
    |--------------------------------------------------------------------------
    | Web Push (Spur W, portiert aus RailTime)
    |--------------------------------------------------------------------------
    */

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function wantsWebPush(PushCategory|string $category): bool
    {
        $categoryValue = $category instanceof PushCategory ? $category->value : $category;

        return (bool) config('webpush.enabled')
            && $this->notificationPreferences()
                ->where('category', $categoryValue)
                ->where('web_push_enabled', true)
                ->exists();
    }

    /**
     * Beim ersten Abo eines Geraets werden alle Kategorien aktiviert.
     * `firstOrCreate` schreibt bewusst nur, was noch fehlt — ein spaeter
     * abgeschaltetes Thema bleibt beim naechsten Geraet abgeschaltet.
     */
    public function enableDefaultPushPreferences(): void
    {
        foreach (PushCategory::cases() as $category) {
            $this->notificationPreferences()->firstOrCreate(
                ['category' => $category->value],
                ['web_push_enabled' => true],
            );
        }
    }

    /**
     * Ueberschreibt die Variante aus HasPushSubscriptions: widerrufene Abos
     * bleiben aussen vor, und ein Testversand kann gezielt ein einzelnes
     * Geraet adressieren statt alle.
     */
    public function routeNotificationForWebPush(?object $notification = null): Collection
    {
        $subscriptions = $this->pushSubscriptions()->whereNull('revoked_at');

        if ($notification instanceof FactoryWebPushNotification
            && $notification->targetSubscriptionId !== null) {
            $subscriptions->whereKey($notification->targetSubscriptionId);
        }

        return $subscriptions->get();
    }

    /**
     * Ueberschreibt die Variante aus HasPushSubscriptions: der Endpunkt liegt
     * verschluesselt in der Datenbank und ist deshalb nicht direkt suchbar.
     */
    public function deletePushSubscription($endpoint): void
    {
        $this->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint((string) $endpoint))
            ->delete();
    }
}
