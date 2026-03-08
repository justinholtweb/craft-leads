<?php

namespace justinholtweb\leads\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\leads\Plugin;
use yii\web\Response;

class SubmissionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('leads:viewSubmissions');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $popupId = $request->getQueryParam('popupId');
        $page = (int)$request->getQueryParam('page', 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $submissions = Plugin::getInstance()->submissions->getSubmissions(
            $popupId ? (int)$popupId : null,
            $limit,
            $offset
        );

        $total = Plugin::getInstance()->submissions->getTotalSubmissions(
            $popupId ? (int)$popupId : null
        );

        return $this->renderTemplate('leads/submissions/index', [
            'submissions' => $submissions,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'popupId' => $popupId,
        ]);
    }

    public function actionExport(): Response
    {
        $this->requirePermission('leads:exportSubmissions');

        $popupId = Craft::$app->getRequest()->getQueryParam('popupId');
        $submissions = Plugin::getInstance()->submissions->exportSubmissions(
            $popupId ? (int)$popupId : null
        );

        $csv = "Email,Name,Page URL,Sync Status,Date\n";
        foreach ($submissions as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s\n",
                $this->escapeCsv($row['email']),
                $this->escapeCsv($row['name'] ?? ''),
                $this->escapeCsv($row['pageUrl']),
                $row['syncStatus'],
                $row['dateCreated']
            );
        }

        $response = Craft::$app->getResponse();
        $response->content = $csv;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/csv');
        $response->getHeaders()->set('Content-Disposition', 'attachment; filename="leads-submissions.csv"');

        return $response;
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('leads:deleteSubmissions');

        $id = Craft::$app->getRequest()->getRequiredBodyParam('id');

        if (Plugin::getInstance()->submissions->deleteSubmission((int)$id)) {
            Craft::$app->getSession()->setNotice(Craft::t('leads', 'Submission deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('leads', 'Couldn\'t delete submission.'));
        }

        return $this->redirectToPostedUrl();
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
