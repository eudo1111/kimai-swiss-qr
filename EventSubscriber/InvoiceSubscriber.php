<?php

namespace KimaiPlugin\SwissQrBundle\EventSubscriber;

use App\Event\InvoicePreRenderEvent;
use KimaiPlugin\SwissQrBundle\Service\SwissQrService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Repository\InvoiceRepository;

class InvoiceSubscriber implements EventSubscriberInterface
{

    public function __construct(private readonly InvoiceRepository $invoiceRepository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvoicePreRenderEvent::class => 'onInvoicePreRender',
        ];
    }

    public function onInvoicePreRender(InvoicePreRenderEvent $event): void
    {
        $model = $event->getModel();
        $event->getModel()->addModelHydrator(new SwissQrService());
    }
}