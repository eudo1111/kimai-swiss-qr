<?php
namespace KimaiPlugin\SwissQrBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SwissQrController extends AbstractController
{
    #[Route(path: '/qrcodes/{filename}', name: 'swissqr_qrcode', requirements: ['filename' => '.+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function serveQrCode(string $filename, Request $request): BinaryFileResponse
    {
        $cleanfilename = str_replace('-', '', $filename);
        $baseDir = $this->getParameter('kernel.project_dir') . '/var/data/qrcodes/';
        $filePath = realpath($baseDir . $cleanfilename);

        // Security: ensure the file is inside the qrcodes directory
        if (!$filePath || strpos($filePath, realpath($baseDir)) !== 0 || !is_file($filePath)) {
            throw $this->createNotFoundException('QR code not found.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $cleanfilename);

        return $response;
    }
}