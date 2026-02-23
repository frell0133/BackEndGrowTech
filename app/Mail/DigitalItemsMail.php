<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DigitalItemsMail extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;
    public array $items;

    public function __construct(Order $order, array $items)
    {
        $this->order = $order;
        $this->items = $items;
    }

    public function build()
    {
        return $this->subject('Invoice ' . $this->order->invoice_number . ' - Pesanan Digital Kamu')
            ->view('emails.digital-items')
            ->with([
                'order' => $this->order,
                'items' => $this->items,
            ]);
    }
}