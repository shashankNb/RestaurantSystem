<?php

namespace App\Mail;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a restaurant's owner that its back office is ready (sent by restaurant:create). A
 * new account gets a link to choose a password; someone who already has an account gets a
 * link to sign in.
 */
class OwnerInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Restaurant $restaurant,
        public User $owner,
        public string $url,
        public bool $newAccount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your back office for {$this->restaurant->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.owners.invitation',
            with: [
                'restaurant' => $this->restaurant,
                'firstName' => strtok($this->owner->name, ' ') ?: $this->owner->name,
                'url' => $this->url,
                'newAccount' => $this->newAccount,
                'signInUrl' => url('/admin/login'),
                'expiresInMinutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}
