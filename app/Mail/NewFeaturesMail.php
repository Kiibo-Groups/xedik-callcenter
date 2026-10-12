<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;

class NewFeaturesMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $recipientName = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New in XEDIK CRM: Email, Reports & More',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-features');
    }

    public function build(): static
    {
        $imgPath = storage_path('app/email-images');

        $images = [
            'logo'         => 'logo.png',
            'img_outbound' => 'img_outbound.png',
            'img_compose'  => 'img_compose.png',
            'img_lead'     => 'img_lead.png',
            'img_report'   => 'img_report.png',
        ];

        return $this
            ->view('emails.new-features')
            ->withSymfonyMessage(function ($message) use ($imgPath, $images) {
                $body = $message->getBody();

                $parts = [];
                foreach ($images as $cid => $filename) {
                    $data  = file_get_contents("$imgPath/$filename");
                    $part  = new DataPart($data, $filename, 'image/png');
                    $part->asInline();
                    $part->setContentId($cid . '@XEDIK');
                    $parts[] = $part;
                }

                $related = new RelatedPart($body, ...$parts);
                $message->setBody($related);
            });
    }
}