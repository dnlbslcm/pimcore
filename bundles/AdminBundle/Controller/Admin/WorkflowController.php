<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\Admin;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete as ConcreteObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ValidationException;
use Pimcore\Tool\Console;
use Pimcore\Workflow\ActionsButtonService;
use Pimcore\Workflow\Manager;
use Pimcore\Workflow\Notes\CustomHtmlServiceInterface;
use Pimcore\Workflow\Place\StatusInfo;
use Pimcore\Workflow\Transition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Workflow;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/workflow")
 *
 * @internal
 */
class WorkflowController extends AdminAbstractController implements KernelControllerEventInterface
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    /**
     * @var Document|Asset|ConcreteObject|null $element
     */
    private $element;

    /**
     * Returns a JSON of the available workflow actions to the admin panel
     *
     * @Route("/get-workflow-form", name="pimcore_admin_workflow_getworkflowform")
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getWorkflowFormAction(Request $request, Manager $workflowManager)
    {
        try {
            $workflow = $workflowManager->getWorkflowIfExists($this->element, (string) $request->get('workflowName'));

            if (empty($workflow)) {
                $wfConfig = [
                    'message' => 'workflow not found',
                ];
            } else {
                //this is the default returned workflow data
                $wfConfig = [
                    'message' => '',
                    'notes_enabled' => false,
                    'notes_required' => false,
                    'additional_fields' => [],
                ];

                $enabledTransitions = $workflow->getEnabledTransitions($this->element);
                $transition = null;
                foreach ($enabledTransitions as $_transition) {
                    if ($_transition->getName() === $request->get('transitionName')) {
                        $transition = $_transition;
                    }
                }

                if (!$transition instanceof Transition) {
                    $wfConfig['message'] = sprintf('transition %s currently not allowed', (string) $request->get('transitionName'));
                } else {
                    $wfConfig['notes_required'] = $transition->getNotesCommentRequired();
                    $wfConfig['additional_fields'] = [];
                }
            }
        } catch (\Exception $e) {
            $wfConfig['message'] = $e->getMessage();
        }

        return $this->adminJson($wfConfig);
    }

    /**
     * @Route("/submit-workflow-transition", name="pimcore_admin_workflow_submitworkflowtransition", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function submitWorkflowTransitionAction(Request $request, Registry $workflowRegistry, Manager $workflowManager)
    {
        $workflowOptions = $request->get('workflow', []);
        $workflow = $workflowRegistry->get($this->element, $request->get('workflowName'));

        if ($workflow->can($this->element, $request->get('transition'))) {
            try {
                $workflowManager->applyWithAdditionalData($workflow, $this->element, $request->get('transition'), $workflowOptions, true);

                $data = [
                    'success' => true,
                    'callback' => 'reloadObject',
                ];
            } catch (ValidationException $e) {
                $reason = '';
                if (count((array)$e->getSubItems()) > 0) {
                    $reason = '<ul>' . implode('', array_map(function ($item) {
                        return '<li>' . $item . '</li>';
                    }, $e->getSubItems())) . '</ul>';
                }

                $data = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'reasons' => [$reason],

                ];
            } catch (\Exception $e) {
                $data = [
                    'success' => false,
                    'message' => 'error performing action on this element',
                    'reasons' => [$e->getMessage()],
                ];
            }
        } else {
            $blockTransitionList = $workflow->buildTransitionBlockerList($this->element, $request->get('transition'));

            $reasons = array_map(function ($blockTransitionItem) {
                return $blockTransitionItem->getMessage();
            }, iterator_to_array($blockTransitionList->getIterator(), true));

            $data = [
                'success' => false,
                'message' => 'transition failed',
                'reasons' => $reasons,
            ];
        }

        return $this->adminJson($data);
    }

    /**
     * @Route("/submit-global-action", name="pimcore_admin_workflow_submitglobal", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function submitGlobalAction(Request $request, Registry $workflowRegistry, Manager $workflowManager)
    {
        $workflowOptions = $request->get('workflow', []);
        $workflow = $workflowRegistry->get($this->element, $request->get('workflowName'));

        try {
            $workflowManager->applyGlobalAction($workflow, $this->element, $request->get('transition'), $workflowOptions, true);

            $data = [
                'success' => true,
                'callback' => 'reloadObject',
            ];
        } catch (ValidationException $e) {
            $reason = '';
            if (count((array)$e->getSubItems()) > 0) {
                $reason = '<ul>' . implode('', array_map(function ($item) {
                    return '<li>' . $item . '</li>';
                }, $e->getSubItems())) . '</ul>';
            }

            $data = [
                'success' => false,
                'message' => $e->getMessage(),
                'reasons' => [$reason],

            ];
        } catch (\Exception $e) {
            $data = [
                'success' => false,
                'message' => 'error performing action on this element',
                'reasons' => [$e->getMessage()],
            ];
        }

        return $this->adminJson($data);
    }

    /**
     * Returns the JSON needed by the workflow elements detail tab store
     *
     * @Route("/get-workflow-details", name="pimcore_admin_workflow_getworkflowdetailsstore")
     *
     * @param Request $request
     * @param Manager $workflowManager
     * @param StatusInfo $placeStatusInfo
     * @param RouterInterface $router
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function getWorkflowDetailsStore(Request $request, Manager $workflowManager, StatusInfo $placeStatusInfo, RouterInterface $router, ActionsButtonService $actionsButtonService)
    {
        $data = [];

        foreach ($workflowManager->getAllWorkflowsForSubject($this->element) as $workflow) {
            $workflowConfig = $workflowManager->getWorkflowConfig($workflow->getName());

            $svg = null;
            $msg = '';

            try {
                $svg = $this->getWorkflowSvg($workflow);
            } catch (\InvalidArgumentException $e) {
                $msg = $e->getMessage();
            }

            $url = $router->generate(
                'pimcore_admin_workflow_show_graph',
                [
                    'cid' => $request->get('cid'),
                    'ctype' => $request->get('ctype'),
                    'workflow' => $workflow->getName(),
                ]
            );

            $allowedTransitions = $actionsButtonService->getAllowedTransitions($workflow, $this->element);
            $globalActions = $actionsButtonService->getGlobalActions($workflow, $this->element);

            $data[] = [
                'workflowName' => $workflowConfig->getLabel(),
                'placeInfo' => $placeStatusInfo->getAllPalacesHtml($this->element, $workflow->getName()),
                'graph' => $msg ?: '<a href="' . $url .'" target="_blank"><div class="workflow-graph-preview">'.$svg.'</div></a>',
                'allowedTransitions' => $allowedTransitions,
                'globalActions' => $globalActions,
            ];
        }

        return $this->adminJson([
            'data' => $data,
            'success' => true,
            'total' => count($data),
        ]);
    }

    /**
     * Returns the JSON needed by the workflow elements detail tab store
     *
     * @Route("/show-graph", name="pimcore_admin_workflow_show_graph")
     *
     * @param Request $request
     * @param Manager $workflowManager
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function showGraph(Request $request, Manager $workflowManager)
    {
        $workflow = $workflowManager->getWorkflowByName($request->get('workflow'));

        $response = new Response($this->getWorkflowSvg($workflow));
        $response->headers->set('Content-Type', 'image/svg+xml');

        return $response;
    }

    /**
     * Get custom HTML for the workflow transition submit modal, depending whether it is configured or not.
     *
     * @Route("/modal-custom-html", name="pimcore_admin_workflow_modal_custom_html", methods={"POST"})
     *
     * @param Request $request
     * @param Registry $workflowRegistry
     * @param Manager $manager
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function getModalCustomHtml(Request $request, Registry $workflowRegistry, Manager $manager)
    {
        $workflow = $workflowRegistry->get($this->element, $request->get('workflowName'));

        if ($request->get('isGlobalAction') == 'true') {
            $globalAction = $manager->getGlobalAction($workflow->getName(), $request->get('transition'));
            if ($globalAction) {
                return $this->customHtmlResponse($globalAction->getCustomHtmlService());
            }
        } elseif ($workflow->can($this->element, $request->get('transition'))) {
            $enabledTransitions = $workflow->getEnabledTransitions($this->element);
            $transition = null;
            foreach ($enabledTransitions as $_transition) {
                if ($_transition->getName() === $request->get('transition')) {
                    $transition = $_transition;
                }
            }

            if ($transition instanceof Transition) {
                return $this->customHtmlResponse($transition->getCustomHtmlService());
            }
        }

        $data = [
            'success' => false,
            'message' => 'error validating the action on this element, element cannot peform this action',
        ];

        return new JsonResponse($data);
    }

    private function customHtmlResponse(CustomHtmlServiceInterface $customHtmlService = null): JsonResponse
    {
        $data = [
            'success' => true,
            'customHtml' => [],
        ];

        if ($customHtmlService) {
            foreach (['top', 'center', 'bottom'] as $position) {
                $data['customHtml'][$position] = $customHtmlService->renderHtmlForRequestedPosition($this->element, $position);
            }
        }

        return new JsonResponse($data);
    }

    /**
     * @param Workflow $workflow
     *
     * @return string
     *
     * @throws \Exception
     */
    private function getWorkflowSvg(Workflow $workflow)
    {
        $marking = $workflow->getMarking($this->element);

        $php = Console::getExecutable('php');
        $dot = Console::getExecutable('dot');

        if (!$php) {
            throw new \InvalidArgumentException($this->translator->trans('workflow_cmd_not_found', ['php'], 'admin'));
        }

        if (!$dot) {
            throw new \InvalidArgumentException($this->translator->trans('workflow_cmd_not_found', ['dot'], 'admin'));
        }

        $cmd = $php . ' ' . PIMCORE_PROJECT_ROOT . '/bin/console pimcore:workflow:dump ${WNAME} ${WPLACES} | ${DOT} -Tsvg';
        $params = [
            'WNAME' => $workflow->getName(),
            'WPLACES' => implode(' ', array_keys($marking->getPlaces())),
            'DOT' => $dot,
        ];

        Console::addLowProcessPriority($cmd);
        $process = Process::fromShellCommandline($cmd);
        $process->run(null, $params);

        return $process->getOutput();
    }

    /**
     * @template T of Document|Asset|DataObject
     *
     * @param T $element
     *
     * @return T
     */
    protected function getLatestVersion($element)
    {
        if (
            $element instanceof Document\Folder
            || $element instanceof Asset\Folder
            || $element instanceof DataObject\Folder
            || $element instanceof Document\Hardlink
            || $element instanceof Document\Link
        ) {
            return $element;
        }

        //TODO move this maybe to a service method, since this is also used in DataObjectController and DocumentControllers
        if ($element instanceof Document\PageSnippet) {
            $latestVersion = $element->getLatestVersion();
            if ($latestVersion) {
                $latestDoc = $latestVersion->loadData();
                if ($latestDoc instanceof Document\PageSnippet) {
                    $element = $latestDoc;
                }
            }
        }

        if ($element instanceof DataObject\Concrete) {
            $latestVersion = $element->getLatestVersion();
            if ($latestVersion) {
                $latestObj = $latestVersion->loadData();
                if ($latestObj instanceof ConcreteObject) {
                    $element = $latestObj;
                }
            }
        }

        return $element;
    }

    /**
     * @param ControllerEvent $event
     *
     * @throws \Exception
     */
    public function onKernelControllerEvent(ControllerEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->get('ctype') === 'document') {
            $this->element = Document::getById((int) $request->get('cid', 0));
        } elseif ($request->get('ctype') === 'asset') {
            $this->element = Asset::getById((int) $request->get('cid', 0));
        } elseif ($request->get('ctype') === 'object') {
            $this->element = ConcreteObject::getById((int) $request->get('cid', 0));
        }

        if (!$this->element) {
            throw new \Exception('Cannot load element' . $request->get('cid') . ' of type \'' . $request->get('ctype') . '\'');
        }

        //get the latest available version of the element -
        $this->element = $this->getLatestVersion($this->element);
        $this->element->setUserModification($this->getAdminUser()->getId());
    }
}
