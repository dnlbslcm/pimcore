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

namespace Pimcore\Bundle\AdminBundle\Controller\Reports;

use Pimcore\Model\Element\Service;
use Pimcore\Model\Exception\ConfigWriteException;
use Pimcore\Model\Tool\CustomReport;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\InvalidArgumentException;

/**
 * @Route("/custom-report")
 *
 * @internal
 */
class CustomReportController extends ReportsControllerBase
{
    /**
     * @Route("/tree", name="pimcore_admin_reports_customreport_tree", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function treeAction(Request $request)
    {
        $this->checkPermission('reports_config');
        $reports = CustomReport\Config::getReportsList();

        return $this->adminJson($reports);
    }

    /**
     * @Route("/portlet-report-list", name="pimcore_admin_reports_customreport_portletreportlist", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function portletReportListAction(Request $request)
    {
        $this->checkPermission('reports');
        $reports = CustomReport\Config::getReportsList($this->getAdminUser());

        return $this->adminJson(['data' => $reports]);
    }

    /**
     * @Route("/add", name="pimcore_admin_reports_customreport_add", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function addAction(Request $request)
    {
        $this->checkPermission('reports_config');

        $success = false;

        $this->isValidConfigName($request->get('name'));

        $report = CustomReport\Config::getByName($request->get('name'));

        if (!$report) {
            $report = new CustomReport\Config();
            if (!$report->isWriteable()) {
                throw new ConfigWriteException();
            }

            $report->setName($request->get('name'));
            $report->save();

            $success = true;
        }

        return $this->adminJson(['success' => $success, 'id' => $report->getName()]);
    }

    /**
     * @Route("/delete", name="pimcore_admin_reports_customreport_delete", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function deleteAction(Request $request)
    {
        $this->checkPermission('reports_config');

        $report = CustomReport\Config::getByName($request->get('name'));
        if (!$report) {
            throw $this->createNotFoundException();
        }
        if (!$report->isWriteable()) {
            throw new ConfigWriteException();
        }

        $report->delete();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/clone", name="pimcore_admin_reports_customreport_clone", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function cloneAction(Request $request)
    {
        $this->checkPermission('reports_config');

        $newName = $request->get('newName');
        $this->isValidConfigName($newName);
        $report = CustomReport\Config::getByName($newName);
        if ($report) {
            throw new \Exception('report already exists');
        }

        $report = CustomReport\Config::getByName($request->get('name'));
        if (!$report) {
            throw $this->createNotFoundException();
        }
        $reportData = $this->encodeJson($report);
        $reportData = $this->decodeJson($reportData);

        unset($reportData['name']);
        $reportData['name'] = $newName;

        foreach ($reportData as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($report, $setter)) {
                $report->$setter($value);
            }
        }

        $report->save();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/get", name="pimcore_admin_reports_customreport_get", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getAction(Request $request)
    {
        $this->checkPermissionsHasOneOf(['reports_config', 'reports']);

        $report = CustomReport\Config::getByName($request->get('name'));
        if (!$report) {
            throw $this->createNotFoundException();
        }
        $data = $report->getObjectVars();
        $data['writeable'] = $report->isWriteable();

        return $this->adminJson($data);
    }

    /**
     * @Route("/update", name="pimcore_admin_reports_customreport_update", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function updateAction(Request $request)
    {
        $this->checkPermission('reports_config');
        $this->isValidConfigName($request->get('name'));
        $report = CustomReport\Config::getByName($request->get('name'));
        if (!$report) {
            throw $this->createNotFoundException();
        }
        if (!$report->isWriteable()) {
            throw new ConfigWriteException();
        }

        $data = $this->decodeJson($request->get('configuration'));

        if (!is_array($data['yAxis'])) {
            $data['yAxis'] = strlen($data['yAxis']) ? [$data['yAxis']] : [];
        }

        foreach ($data as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($report, $setter)) {
                $report->$setter($value);
            }
        }

        $report->save();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/column-config", name="pimcore_admin_reports_customreport_columnconfig", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function columnConfigAction(Request $request)
    {
        $this->checkPermission('reports_config');

        $report = CustomReport\Config::getByName($request->get('name'));
        if (!$report) {
            throw $this->createNotFoundException();
        }
        $columnConfiguration = $report->getColumnConfiguration();
        if (!is_array($columnConfiguration)) {
            $columnConfiguration = [];
        }

        $configuration = json_decode($request->get('configuration'));
        $configuration = $configuration[0] ?? null;

        $success = false;
        $errorMessage = null;

        $result = [];

        try {
            $adapter = CustomReport\Config::getAdapter($configuration);
            $columns = $adapter->getColumns($configuration);
            if (!is_array($columns)) {
                $columns = [];
            }

            foreach ($columnConfiguration as $item) {
                $name = $item['name'];
                if (in_array($name, $columns)) {
                    $result[] = $name;
                    array_splice($columns, array_search($name, $columns), 1);
                }
            }
            foreach ($columns as $remainingColumn) {
                $result[] = $remainingColumn;
            }

            $success = true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        return $this->adminJson([
            'success' => $success,
            'columns' => $result,
            'errorMessage' => $errorMessage,
        ]);
    }

    /**
     * @Route("/get-report-config", name="pimcore_admin_reports_customreport_getreportconfig", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getReportConfigAction(Request $request)
    {
        $this->checkPermission('reports');

        $reports = [];

        $list = new CustomReport\Config\Listing();
        $items = $list->getDao()->loadForGivenUser($this->getAdminUser());

        foreach ($items as $report) {
            $reports[] = [
                'name' => htmlspecialchars($report->getName()),
                'niceName' => htmlspecialchars($report->getNiceName()),
                'iconClass' => htmlspecialchars($report->getIconClass()),
                'group' => htmlspecialchars($report->getGroup()),
                'groupIconClass' => htmlspecialchars($report->getGroupIconClass()),
                'menuShortcut' => $report->getMenuShortcut(),
                'reportClass' => htmlspecialchars($report->getReportClass()),
            ];
        }

        return $this->adminJson([
            'success' => true,
            'reports' => $reports,
        ]);
    }

    /**
     * @Route("/data", name="pimcore_admin_reports_customreport_data", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function dataAction(Request $request)
    {
        $this->checkPermission('reports');

        $offset = $request->get('start', 0);
        $limit = $request->get('limit', 40);
        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings(array_merge($request->request->all(), $request->query->all()));
        $sort = null;
        $dir = null;
        if ($sortingSettings['orderKey']) {
            $sort = $sortingSettings['orderKey'];
            $dir = $sortingSettings['order'];
        }

        $filters = ($request->get('filter') ? json_decode($request->get('filter'), true) : null);

        $drillDownFilters = $request->get('drillDownFilters', null);

        $config = CustomReport\Config::getByName($request->get('name'));
        if (!$config) {
            throw $this->createNotFoundException();
        }
        $configuration = $config->getDataSourceConfig();

        $adapter = CustomReport\Config::getAdapter($configuration, $config);

        $result = $adapter->getData($filters, $sort, $dir, $offset, $limit, null, $drillDownFilters);

        return $this->adminJson([
            'success' => true,
            'data' => $result['data'],
            'total' => $result['total'],
        ]);
    }

    /**
     * @Route("/drill-down-options", name="pimcore_admin_reports_customreport_drilldownoptions", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function drillDownOptionsAction(Request $request)
    {
        $this->checkPermission('reports');

        $field = $request->get('field');
        $filters = ($request->get('filter') ? json_decode($request->get('filter'), true) : null);
        $drillDownFilters = $request->get('drillDownFilters', null);

        $config = CustomReport\Config::getByName($request->get('name'));
        if (!$config) {
            throw $this->createNotFoundException();
        }
        $configuration = $config->getDataSourceConfig();

        $adapter = CustomReport\Config::getAdapter($configuration, $config);
        $result = $adapter->getAvailableOptions($filters, $field, $drillDownFilters);

        return $this->adminJson([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    /**
     * @Route("/chart", name="pimcore_admin_reports_customreport_chart", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function chartAction(Request $request)
    {
        $this->checkPermission('reports');

        $sort = $request->get('sort');
        $dir = $request->get('dir');
        $filters = ($request->get('filter') ? json_decode($request->get('filter'), true) : null);
        $drillDownFilters = $request->get('drillDownFilters', null);

        $config = CustomReport\Config::getByName($request->get('name'));
        if (!$config) {
            throw $this->createNotFoundException();
        }

        $configuration = $config->getDataSourceConfig();

        $adapter = CustomReport\Config::getAdapter($configuration, $config);
        $result = $adapter->getData($filters, $sort, $dir, null, null, null, $drillDownFilters);

        return $this->adminJson([
            'success' => true,
            'data' => $result['data'],
            'total' => $result['total'],
        ]);
    }

    protected function getTemporaryFileFromFileName(string $exportFileName): string
    {
        $exportFileName = basename($exportFileName);
        if (!str_ends_with($exportFileName, '.csv')) {
            throw new InvalidArgumentException($exportFileName . ' is not a valid csv file.');
        }

        return PIMCORE_SYSTEM_TEMP_DIRECTORY . '/' . $exportFileName;
    }

    /**
     * @Route("/create-csv", name="pimcore_admin_reports_customreport_createcsv", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function createCsvAction(Request $request)
    {
        $this->checkPermission('reports');

        set_time_limit(300);

        $sort = $request->get('sort');
        $dir = $request->get('dir');
        $filters = $request->get('filter') ? json_decode(urldecode($request->get('filter')), true) : null;
        $drillDownFilters = $request->get('drillDownFilters', null);
        if ($drillDownFilters) {
            $drillDownFilters = json_decode($drillDownFilters, true);
        }
        $includeHeaders = $request->get('headers', false);

        $config = CustomReport\Config::getByName($request->get('name'));
        if (!$config) {
            throw $this->createNotFoundException();
        }

        $columns = $config->getColumnConfiguration();
        $fields = [];
        foreach ($columns as $column) {
            if ($column['export']) {
                $fields[] = $column['name'];
            }
        }

        $configuration = $config->getDataSourceConfig();

        $adapter = CustomReport\Config::getAdapter($configuration, $config);

        $offset = $request->get('offset', 0);
        $limit = 5000;
        $tempData = [];
        $result = $adapter->getData($filters, $sort, $dir, $offset * $limit, $limit, $fields, $drillDownFilters);
        ++$offset;

        if (!($exportFile = $request->get('exportFile'))) {
            $exportFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/report-export-' . uniqid() . '.csv';
            @unlink($exportFile);
        } else {
            $exportFile = $this->getTemporaryFileFromFileName($exportFile);
        }

        $fp = fopen($exportFile, 'a');

        if ($includeHeaders) {
            fputcsv($fp, $fields, ';');
        }

        foreach ($result['data'] as $row) {
            $row = Service::escapeCsvRecord($row);
            fputcsv($fp, array_values($row), ';');
        }

        fclose($fp);

        $progress = $result['total'] ? ($offset * $limit) / $result['total'] : 1;
        $progress = $progress > 1 ? 1 : $progress;

        return new JsonResponse([
            'exportFile' => basename($exportFile),
            'offset' => $offset,
            'progress' => $progress,
            'finished' => empty($result['data']) || count($result['data']) < $limit,
        ]);
    }

    /**
     * @Route("/download-csv", name="pimcore_admin_reports_customreport_downloadcsv", methods={"GET"})
     *
     * @param Request $request
     *
     * @return BinaryFileResponse
     */
    public function downloadCsvAction(Request $request)
    {
        $this->checkPermission('reports');
        if ($exportFile = $request->get('exportFile')) {
            $exportFile = $this->getTemporaryFileFromFileName($exportFile);
            $response = new BinaryFileResponse($exportFile);
            $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'export.csv');
            $response->deleteFileAfterSend(true);

            return $response;
        }

        throw new FileNotFoundException("File \"$exportFile\" not found!");
    }

    /**
     * @throws \Exception
     */
    public function isValidConfigName(string $configName)
    {
        if(!preg_match('/^[a-zA-Z0-9_\-]+$/', $configName)) {
            throw new \Exception('The customer report name is invalid');
        }
    }
}
