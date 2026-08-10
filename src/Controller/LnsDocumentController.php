<?php

namespace App\Controller;

use App\Entity\LnsDocument;
use App\Entity\User;
use App\Form\LnsDocumentType;
use App\Repository\LnsDocumentRepository;
use App\Service\Document\LnsDocumentContentManager;
use App\Service\Document\LnsDocumentManager;
use App\Service\Document\LnsDocumentVersionConflict;
use App\Service\Pdf\LnsDocumentPdfGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/documents', name: 'lns_documents_')]
#[IsGranted('ROLE_USER')]
class LnsDocumentController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, LnsDocumentRepository $documentRepository): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $documents = $query !== '' ? $documentRepository->search($query) : $documentRepository->findAllOrdered();

        return $this->render('lns_document/index.html.twig', [
            'documents' => $documents,
            'searchQuery' => $query,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        LnsDocumentContentManager $contentManager,
        LnsDocumentManager $documentManager,
    ): Response {
        $document = new LnsDocument();
        $content = $contentManager->defaultContent();
        $form = $this->createForm(LnsDocumentType::class, $document, [
            'content_json' => json_encode($content, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'revision' => $document->getEditVersion(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $author = $this->getUser() instanceof User ? $this->getUser() : null;
                $documentManager->save(
                    $document,
                    (string) $form->get('contentJson')->getData(),
                    $author,
                    (int) $form->get('revision')->getData(),
                );

                $this->addFlash('success', 'Le document a été créé.');

                if ((string) $request->request->get('document_action') === 'save_pdf') {
                    return $this->redirectToRoute('lns_documents_pdf', ['id' => $document->getId()]);
                }

                return $this->redirectToRoute('lns_documents_edit', ['id' => $document->getId()]);
            } catch (LnsDocumentVersionConflict|\InvalidArgumentException $exception) {
                $form->get('contentJson')->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('lns_document/editor.html.twig', [
            'document' => $document,
            'form' => $form->createView(),
            'isNew' => true,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(int $id, LnsDocumentRepository $documentRepository): Response
    {
        return $this->render('lns_document/show.html.twig', [
            'document' => $this->findDocument($id, $documentRepository),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    public function edit(
        int $id,
        Request $request,
        LnsDocumentRepository $documentRepository,
        LnsDocumentManager $documentManager,
    ): Response {
        $document = $this->findDocument($id, $documentRepository);
        $form = $this->createForm(LnsDocumentType::class, $document, [
            'content_json' => json_encode($document->getContent(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'revision' => $document->getEditVersion(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $documentManager->save(
                    $document,
                    (string) $form->get('contentJson')->getData(),
                    null,
                    (int) $form->get('revision')->getData(),
                );

                $this->addFlash('success', 'Les modifications ont été enregistrées.');

                if ((string) $request->request->get('document_action') === 'save_pdf') {
                    return $this->redirectToRoute('lns_documents_pdf', ['id' => $document->getId()]);
                }

                return $this->redirectToRoute('lns_documents_edit', ['id' => $document->getId()]);
            } catch (LnsDocumentVersionConflict|\InvalidArgumentException $exception) {
                $form->get('contentJson')->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('lns_document/editor.html.twig', [
            'document' => $document,
            'form' => $form->createView(),
            'isNew' => false,
        ]);
    }

    #[Route('/autosave', name: 'autosave_new', methods: ['POST'])]
    #[Route('/{id}/autosave', name: 'autosave', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function autosave(
        Request $request,
        LnsDocumentRepository $documentRepository,
        LnsDocumentManager $documentManager,
        ?int $id = null,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('lns_document_autosave', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['message' => 'Jeton CSRF invalide. Rechargez la page.'], Response::HTTP_FORBIDDEN);
        }

        $isNew = $id === null;
        $document = $isNew ? new LnsDocument() : $documentRepository->find($id);

        if (!$document instanceof LnsDocument) {
            return new JsonResponse(['message' => 'Document LNS introuvable.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $payload = $request->toArray();
            $author = $this->getUser() instanceof User ? $this->getUser() : null;
            $documentManager->autosave($document, $payload, $author);
        } catch (LnsDocumentVersionConflict $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'revision' => $exception->getCurrentVersion(),
            ], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JsonException) {
            return new JsonResponse(['message' => 'Le brouillon envoyé est invalide.'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'message' => 'Brouillon enregistré.',
            'documentId' => $document->getId(),
            'revision' => $document->getEditVersion(),
            'updatedAt' => $document->getUpdatedAt()->format(\DATE_ATOM),
            'editUrl' => $this->generateUrl('lns_documents_edit', ['id' => $document->getId()]),
            'autosaveUrl' => $this->generateUrl('lns_documents_autosave', ['id' => $document->getId()]),
            'showUrl' => $this->generateUrl('lns_documents_show', ['id' => $document->getId()]),
        ], $isNew ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function delete(
        int $id,
        Request $request,
        LnsDocumentRepository $documentRepository,
        LnsDocumentManager $documentManager,
    ): Response {
        $document = $this->findDocument($id, $documentRepository);

        if (!$this->isCsrfTokenValid('delete_lns_document_' . $document->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide. Le document n’a pas été supprimé.');

            return $this->redirectToRoute('lns_documents_index');
        }

        $title = $document->getDisplayTitle();
        $documentManager->delete($document);
        $this->addFlash('success', sprintf('Le document « %s » a été supprimé.', $title));

        return $this->redirectToRoute('lns_documents_index');
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function pdf(
        int $id,
        LnsDocumentRepository $documentRepository,
        LnsDocumentPdfGenerator $pdfGenerator,
    ): Response {
        $document = $this->findDocument($id, $documentRepository);

        if ($document->isDraft()) {
            $this->addFlash('warning', 'Validez le brouillon avec « Enregistrer » avant de générer le PDF.');

            return $this->redirectToRoute('lns_documents_edit', ['id' => $document->getId()]);
        }

        $slug = (new AsciiSlugger())->slug((string) $document->getTitle())->lower()->toString();
        $filename = ($slug !== '' ? $slug : 'document-lns') . '.pdf';

        return new Response($pdfGenerator->generate($document), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function findDocument(int $id, LnsDocumentRepository $documentRepository): LnsDocument
    {
        $document = $documentRepository->find($id);

        if (!$document instanceof LnsDocument) {
            throw $this->createNotFoundException('Document LNS introuvable.');
        }

        return $document;
    }
}
