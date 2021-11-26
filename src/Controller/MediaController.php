<?php

/*
 * This file was created by developers working at BitBag
 * Do you need more information about us and what we do? Visit our https://bitbag.io website!
 * We are hiring developers from all over the world. Join us and start your new, exciting adventure and become part of us: https://bitbag.io/career
*/

declare(strict_types=1);

namespace BitBag\SyliusCmsPlugin\Controller;

use BitBag\SyliusCmsPlugin\Entity\MediaInterface;
use BitBag\SyliusCmsPlugin\Resolver\MediaProviderResolverInterface;
use BitBag\SyliusCmsPlugin\Resolver\MediaResourceResolverInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Resource\ResourceActions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Webmozart\Assert\Assert;

final class MediaController extends ResourceDataProcessingController
{
    /** @var MediaResourceResolverInterface */
    protected $resourceResolver;

    /** @var MediaProviderResolverInterface */
    private $mediaProviderResolver;

    public function renderMediaAction(Request $request): Response
    {
        $configuration = $this->getRequestConfiguration($request);
        /** @var MediaInterface|null $media */
        $media = $this->getMediaForRequestCode($configuration, $request);

        if (null === $media) {
            return new Response();
        }

        $this->eventDispatcher->dispatch(ResourceActions::SHOW, $configuration, $media);

        return new Response($this->mediaProviderResolver->resolveProvider($media)->render($media, $request->get('template')));
    }

    public function downloadMediaAction(Request $request): Response
    {
        $configuration = $this->getRequestConfiguration($request);

        /** @var MediaInterface|null $media */
        $media = $this->getMediaForRequestCode($configuration, $request);

        if (null === $media) {
            return new Response();
        }

        $this->eventDispatcher->dispatch(ResourceActions::SHOW, $configuration, $media);

        /** @var string|null $mediaPath */
        $mediaPath = $media->getPath();
        Assert::notNull($mediaPath);
        Assert::string($this->getParameter('sylius_core.public_dir'));
        $mediaPath = $this->getParameter('sylius_core.public_dir') . '/' . $media->getPath();
        $mediaFile = new File($mediaPath);
        $mediaName = $media->getDownloadName() . '.' . $mediaFile->guessExtension();
        $response = new BinaryFileResponse($mediaPath);

        $response->setContentDisposition(
            $request->get('disposition', ResponseHeaderBag::DISPOSITION_ATTACHMENT),
            $mediaName
        );
        $response->headers->set('Content-Type', $media->getMimeType());

        return $response;
    }

    public function previewAction(Request $request): Response
    {
        $configuration = $this->getRequestConfiguration($request);

        $this->isGrantedOr403($configuration, ResourceActions::CREATE);
        /** @var MediaInterface $media */
        $media = $this->getResourceInterface($request);
        $form = $this->getFormForResource($configuration, $media);
        $mediaTemplate = null;

        $form->handleRequest($request);

        if ($form->isValid()) {
            $defaultLocale = $this->getParameter('locale');
            $mediaTemplate = $this->mediaProviderResolver->resolveProvider($media)->getTemplate();

            $this->resolveFile($media);

            $media->setFallbackLocale($request->get('_locale', $defaultLocale));
            $media->setCurrentLocale($request->get('_locale', $defaultLocale));
        }
        $this->formErrorsFlashHelper->addFlashErrors($form);

        return $this->render($configuration->getTemplate(ResourceActions::CREATE . '.html'), [
            'metadata' => $this->metadata,
            'resource' => $media,
            'mediaTemplate' => $mediaTemplate,
            $this->metadata->getName() => $media,
        ]);
    }

    private function resolveFile(MediaInterface $media): void
    {
        if (null === $media->getFile() && null === $media->getPath()) {
            return;
        }

        $this->setResourceMediaPath($media);
    }

    /**
     * @required
     */
    public function setMediaProviderResolver(MediaProviderResolverInterface $mediaProviderResolver): void
    {
        $this->mediaProviderResolver = $mediaProviderResolver;
    }

    private function getMediaForRequestCode(RequestConfiguration $configuration, Request $request): ?MediaInterface
    {
        $this->isGrantedOr403($configuration, ResourceActions::SHOW);
        $code = $request->get('code');

        return $this->resourceResolver->findOrLog($code);
    }
}
