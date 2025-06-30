<?php

namespace KimaiPlugin\SwissQrBundle\EventSubscriber;

use App\Event\InvoiceCreatedEvent;
use App\Event\InvoiceUpdatedEvent;
use App\Event\InvoicePreRenderEvent;
use App\Entity\InvoiceMeta;
use App\Event\InvoiceMetaDefinitionEvent;
use App\Event\InvoiceMetaDisplayEvent;
use App\Entity\MetaTableTypeInterface;
use KimaiPlugin\SwissQrBundle\Service\SwissQrService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints\Length;
use App\Repository\InvoiceRepository;

class InvoiceSubscriber implements EventSubscriberInterface
{
    private $qrService;

    public function __construct(SwissQrService $qrService, private readonly InvoiceRepository $invoiceRepository)
    {
        $this->qrService = $qrService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvoiceCreatedEvent::class => 'onInvoiceCreated',
            InvoiceUpdatedEvent::class => 'onInvoiceUpdated',
            InvoicePreRenderEvent::class => 'onInvoicePreRender',
            InvoiceMetaDefinitionEvent::class => 'onInvoiceMetaDefinition',
            InvoiceMetaDisplayEvent::class => 'onInvoiceMetaDisplay',
        ];
    }

    public function onInvoiceCreated(InvoiceCreatedEvent $event): void
    {
        $invoice = $event->getInvoice();
        $reference = $this->qrService->generateQrCode($invoice);

        $meta = $invoice->getMetaField('qr_reference');
        if ($meta === null) {
            $meta = $this->createQrReferenceMeta();
            $invoice->setMetaField($meta);
        }
        $meta->setValue($reference);
        $this->invoiceRepository->saveInvoice($invoice);
    }

    public function onInvoiceUpdated(InvoiceUpdatedEvent $event): void
    {
        $invoice = $event->getInvoice();
        $reference = $this->qrService->generateQrCode($invoice);

        $meta = $invoice->getMetaField('qr_reference');
        if ($meta === null) {
            $meta = $this->createQrReferenceMeta();
            $invoice->setMetaField($meta);
        }
        $meta->setValue($reference);
        $this->invoiceRepository->saveInvoice($invoice);
    }

    public function onInvoicePreRender(InvoicePreRenderEvent $event): void
    {
        $model = $event->getModel();
        $reference = $this->qrService->generateQrCode($model);
        $model->setOption('qr_reference', $reference);
    }

    private function createQrReferenceMeta(): MetaTableTypeInterface
    {
        $meta = new InvoiceMeta();
        $meta->setName('qr_reference');
        $meta->setLabel('QR Referenz');
        $meta->setOptions([
            'label' => 'QR Referenz',
            'help' => 'Automatisch erstellte QR Referenz. (Mit Sorgfalt bearbeiten!)'
        ]);
        $meta->setType(TextType::class);
        $meta->addConstraint(new Length(['max' => 27]));
        $meta->setIsVisible(true);
        return $meta;
    }

    public function onInvoiceMetaDefinition(InvoiceMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->createQrReferenceMeta());
    }

    public function onInvoiceMetaDisplay(InvoiceMetaDisplayEvent $event): void
    {
        $event->addField($this->createQrReferenceMeta());
    }
}