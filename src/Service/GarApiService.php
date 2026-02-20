<?php

namespace eduMedia\GarApiBundle\Service;

use DateTimeImmutable;
use eduMedia\GarApiBundle\Service\Exception\GarCertificateConfigurationException;
use eduMedia\GarApiBundle\Service\Exception\GarGlobalReportDownloadException;
use eduMedia\GarApiBundle\Service\Exception\GarInstitutionListLoadException;
use eduMedia\GarApiBundle\Service\Exception\GarReportDownloadException;
use eduMedia\GarApiBundle\Service\Exception\GarReportListGetException;
use eduMedia\GarApiBundle\Service\Exception\GarSubscriptionCreateException;
use eduMedia\GarApiBundle\Service\Exception\GarSubscriptionDatesUpdateException;
use eduMedia\GarApiBundle\Service\Exception\GarSubscriptionDeleteException;
use eduMedia\GarApiBundle\Service\Exception\GarSubscriptionListLoadException;
use eduMedia\GarApiBundle\Service\Exception\GarSubscriptionXmlGetException;
use eduMedia\GarApiBundle\Service\vo\GarAssignment;
use eduMedia\GarApiBundle\Service\vo\GarCreatableSubscriptionInterface;
use eduMedia\GarApiBundle\Service\vo\GarReport;
use eduMedia\GarApiBundle\Service\vo\GarResource;
use eduMedia\GarApiBundle\Service\vo\GarSubscription;
use eduMedia\GarApiBundle\Service\vo\GarSubscriptionFilter;
use Exception;
use SimpleXMLElement;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use ZipArchive;

class GarApiService
{
    private string $distributorId;
    private string $sslCert;
    private string $sslKey;
    private string $remoteEnv;
    private string $cacheDirectory;
    private ?string $reportSslCert;
    private ?string $reportSslKey;

    private ?CurlHttpClient $client = null;
    private ?CurlHttpClient $reportClient = null;

    private const ENDPOINT_PREFIXES = [
        'prod'    => "https://abonnement.gar.education.fr",
        'preprod' => "https://abonnement.partenaire.test-gar.education.fr",
    ];
    private const REPORT_ENDPOINT_PREFIXES = [
        'prod'    => "https://ws-rapports-affectation.gar.education.fr",
        'preprod' => "https://ws-rapports-affectation.partenaire.test-gar.education.fr",
    ];

    public const DATE_FORMAT = "Y-m-d\TH:i:s";

    private ?array $cachedInstitutions = null;
    private ?array $cachedInstitutionUAIs = null;

    public function __construct(
        string  $distributorId,
        string  $sslCert,
        string  $sslKey,
        string  $remoteEnv,
        string  $cacheDirectory,
        ?string $reportSslCert = null,
        ?string $reportSslKey = null
    )
    {
        $this->distributorId = $distributorId;
        $this->sslCert = $sslCert;
        $this->sslKey = $sslKey;
        $this->remoteEnv = $remoteEnv;
        $this->cacheDirectory = $cacheDirectory;
        $this->reportSslKey = $reportSslKey;
        $this->reportSslCert = $reportSslCert;
    }

    public function isProd(): bool
    {
        return $this->remoteEnv === 'prod';
    }

    private function getEndpointPrefix(): string
    {
        return self::ENDPOINT_PREFIXES[$this->remoteEnv];
    }

    private function getEndpoint(string $path): string
    {
        return $this->getEndpointPrefix() . $path;
    }

    private function getReportEndpointPrefix(): string
    {
        return self::REPORT_ENDPOINT_PREFIXES[$this->remoteEnv];
    }

    private function getReportEndpoint(string $path): string
    {
        return $this->getReportEndpointPrefix() . $path;
    }

    private function getInstitutionPath($extension, bool $uaiOnly = false): string
    {
        return $this->cacheDirectory . '/' . date('Y/m/d.') . ($uaiOnly ? 'uai-only.' : '') . $extension;
    }

    private function getClient(): CurlHttpClient
    {
        if ($this->client instanceof CurlHttpClient) {
            return $this->client;
        }

        if (!file_exists($this->sslCert)) {
            throw new FileNotFoundException(sprintf("SSL Cert: %s does not exist", $this->sslCert));
        }

        if (!file_exists($this->sslKey)) {
            throw new FileNotFoundException(sprintf("SSL Key: %s does not exist", $this->sslKey));
        }

        if (!is_dir($this->cacheDirectory)) {
            try {
                mkdir($this->cacheDirectory, 0777, true);
            } catch (Exception) {
                throw new FileNotFoundException("Could not find or create cache directory $this->cacheDirectory");
            }
        }

        $this->client = new CurlHttpClient([
            'local_cert' => $this->sslCert,
            'local_pk'   => $this->sslKey,
            'headers'    => [
                "Content-type: application/xml",
                "Accept: application/xml",
            ],
        ]);

        return $this->client;
    }

    /**
     * @throws GarCertificateConfigurationException
     */
    private function getReportClient(): CurlHttpClient
    {
        if ($this->reportClient instanceof CurlHttpClient) {
            return $this->reportClient;
        }

        if (!isset($this->reportSslKey) || !isset($this->reportSslCert)) {
            throw new GarCertificateConfigurationException("You must configure the report certificate parameters (`report_ssl_*`) to use this feature");
        }

        if (!file_exists($this->reportSslCert)) {
            throw new GarCertificateConfigurationException(sprintf("Report SSL Cert: %s does not exist", $this->reportSslCert));
        }

        if (!file_exists($this->reportSslKey)) {
            throw new GarCertificateConfigurationException(sprintf("Report SSL Key: %s does not exist", $this->reportSslKey));
        }

        $this->reportClient = new CurlHttpClient([
            'local_cert' => $this->reportSslCert,
            'local_pk'   => $this->reportSslKey,
            'headers'    => [
                "Content-type: application/xml",
                "Accept: application/json",
            ],
        ]);

        return $this->reportClient;
    }

    /**
     * @return string
     * @throws GarInstitutionListLoadException
     */
    private function getInstitutionListContents(): string
    {
        $response = $this->getClient()->request('GET', $this->getEndpoint('/etablissements/etablissements.xml'));

        try {
            $content = $response->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            throw new GarInstitutionListLoadException('Could not get institution list contents', previous: $e);
        }

        return $content;
    }

    /**
     * @return array<string, array{
     *     uai: string,
     *     nature_uai: string,
     *     nature_uai_libe: string,
     *     type_uai: string,
     *     type_uai_libe: string,
     *     commune: string,
     *     commune_libe: string,
     *     academie: string,
     *     academie_libe: string,
     *     departement_insee_3: string,
     *     departement_insee_3_libe: string,
     *     appellation_officielle: string,
     *     patronyme_uai: string,
     *     code_postal_uai: string,
     *     localite_acheminement_uai: string,
     *     idENT: string,
     * }>
     *
     * @throws GarInstitutionListLoadException
     */
    public function getInstitutions(): array
    {
        if (isset($this->cachedInstitutions)) {
            return $this->cachedInstitutions;
        }

        $phpPath = $this->getInstitutionPath('php');

        if (file_exists($phpPath)) {
            $this->cachedInstitutions = include $phpPath;

            return $this->cachedInstitutions;
        }

        $xmlPath = $this->getInstitutionPath('xml');
        $content = $this->getInstitutionListContents();

        $this->cachedInstitutions = $this->xmlToInstitutionArray($content);

        if (!is_dir(dirname($xmlPath))) {
            mkdir(dirname($xmlPath), 0777, true);
        }

        if (!is_dir(dirname($phpPath))) {
            mkdir(dirname($phpPath), 0777, true);
        }

        file_put_contents($xmlPath, $content);
        file_put_contents($phpPath, "<?php\nreturn " . var_export($this->cachedInstitutions, true) . ";");

        return $this->cachedInstitutions;
    }

    /**
     * @return string[]
     * @throws GarInstitutionListLoadException
     */
    public function getInstitutionUAIs(): array
    {
        if (isset($this->cachedInstitutionUAIs)) {
            return $this->cachedInstitutionUAIs;
        }

        $phpPath = $this->getInstitutionPath('php', true);

        if (file_exists($phpPath)) {
            $this->cachedInstitutionUAIs = include $phpPath;

            return $this->cachedInstitutionUAIs;
        }

        $content = $this->getInstitutionListContents();
        $this->cachedInstitutionUAIs = array_keys($this->xmlToInstitutionArray($content));

        if (!is_dir(dirname($phpPath))) {
            mkdir(dirname($phpPath), 0777, true);
        }

        file_put_contents($phpPath, "<?php\nreturn " . var_export($this->cachedInstitutionUAIs, true) . ";");

        return $this->cachedInstitutionUAIs;
    }

    private function xmlToInstitutionArray(string $xml): array
    {
        $xml = simplexml_load_string(
            $xml,
            "SimpleXMLElement",
            0,
            "http://www.atosworldline.com/listEtablissement/v1.0/"
        );
        $ar = array();

        foreach ($xml->children() as $institutionNode) {
            $institutionData = array();
            foreach ($institutionNode->children() as $child) {
                $institutionData[$child->getName()] = (string)$child;
            }
            $ar[$institutionData['uai']] = $institutionData;
        }

        return $ar;
    }

    /**
     * @throws GarInstitutionListLoadException
     */
    public function hasInstitution(string $uai): bool
    {
        return in_array($uai, $this->getInstitutionUAIs());
    }

    /**
     * @throws GarSubscriptionListLoadException
     */
    public function getSubscriptions(?GarSubscriptionFilter $filter = null): array
    {
        if (is_null($filter)) {
            $filter = new GarSubscriptionFilter();
        }

        $response = $this->getClient()->request('POST', $this->getEndpoint('/abonnements'), [
            'body' => $filter->getNodeString(),
        ]);

        try {
            $content = $response->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            throw new GarSubscriptionListLoadException('Could not get subscription list contents', previous: $e);
        }

        $xml = simplexml_load_string($content);
        $subscriptions = array();

        foreach ($xml->children() as $subscriptionNode) {
            $garSubscription = GarSubscription::createFromNode($subscriptionNode);

            if ($filter->resourceId && $garSubscription->getResourceId() !== $filter->resourceId) {
                continue;
            }

            $subscriptions[] = $garSubscription;
        }

        return $subscriptions;
    }

    /**
     * @throws GarSubscriptionCreateException
     */
    public function createSubscription(GarCreatableSubscriptionInterface $subscription, GarResource $resource, GarAssignment $assignment): bool
    {
        $cpr = $subscription->getGarSubscriptionResourceProjectCode();
        $cprTag = isset($cpr) ? "<codeProjetRessource>$cpr</codeProjetRessource>" : '';

        $input = <<<XML
            <abonnement xmlns="http://www.atosworldline.com/wsabonnement/v1.0/">
                <idAbonnement>{$subscription->getGarSubscriptionId()}</idAbonnement>
                <idDistributeurCom>$this->distributorId</idDistributeurCom>
                <idRessource>{$resource->getId()}</idRessource>
                <typeIdRessource>{$resource->getType()}</typeIdRessource>
                <libelleRessource>{$resource->getLabel()}</libelleRessource>
                <debutValidite>{$subscription->getGarSubscriptionFrom()->format(self::DATE_FORMAT)}</debutValidite>
                <finValidite>{$subscription->getGarSubscriptionTo()->format(self::DATE_FORMAT)}</finValidite>
                <uaiEtab>{$subscription->getGarUai()}</uaiEtab>
                <categorieAffectation>{$assignment->getCategory()}</categorieAffectation>
                <typeAffectation>{$assignment->getType()}</typeAffectation>
                <nbLicenceGlobale>{$assignment->getNumGlobalLicence()}</nbLicenceGlobale>
                {$assignment->getAudienceTags()}
                {$cprTag}
            </abonnement>
        XML;

        $response = $this->getClient()->request(
            "PUT",
            $this->getEndpoint("/" . $subscription->getGarSubscriptionId()),
            ['body' => $input]
        );

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new GarSubscriptionCreateException('Could not create subscription', previous: $e);
        }

        return $statusCode === 201;
    }

    /**
     * @throws GarSubscriptionXmlGetException
     */
    private function getSubscriptionXml(string $subscriptionId): ?SimpleXMLElement
    {
        $filter = <<<FILTER
            <filtres xmlns="http://www.atosworldline.com/wsabonnement/v1.0/">
                <filtre>
                    <filtreNom>idAbonnement</filtreNom>
                    <filtreValeur>$subscriptionId</filtreValeur>
                </filtre>
            </filtres>
        FILTER;

        $response = $this->getClient()->request('POST', $this->getEndpoint('/abonnements'), [
            'body' => $filter
        ]);

        try {
            $xml = simplexml_load_string($response->getContent());
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            throw new GarSubscriptionXmlGetException('Could not get subscription XML', previous: $e);
        }

        foreach ($xml->children() as $child) {
            return $child;
        }

        return null;
    }

    /**
     * @throws GarSubscriptionXmlGetException
     * @throws GarSubscriptionDatesUpdateException
     */
    public function updateSubscriptionDates(string $subscriptionId, GarCreatableSubscriptionInterface $subscription): bool
    {
        $xml = $this->getSubscriptionXml($subscriptionId);

        if (is_null($xml)) {
            return false;
        }

        // Remove UAI tag
        unset($xml->uaiEtab);

        // Set new tag values
        if ($subscription->getGarSubscriptionFrom() !== null) {
            $xml->debutValidite = $subscription->getGarSubscriptionFrom()->format(self::DATE_FORMAT);
        }

        if ($subscription->getGarSubscriptionTo() !== null) {
            $xml->finValidite = $subscription->getGarSubscriptionTo()->format(self::DATE_FORMAT);
        }

        // Hackish
        $namespacedXml = str_replace('<abonnement>', '<abonnement xmlns="http://www.atosworldline.com/wsabonnement/v1.0/">', $xml->asXML());

        $response = $this->getClient()->request('POST', $this->getEndpoint("/$subscriptionId"), [
            'body' => $namespacedXml
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new GarSubscriptionDatesUpdateException('Could not update subscription dates', previous: $e);
        }

        return $statusCode === 200;
    }

    /**
     * @throws GarSubscriptionDeleteException
     */
    public function deleteSubscription(string $subscriptionId): bool
    {
        $response = $this->getClient()->request(
            "DELETE",
            $this->getEndpoint("/" . $subscriptionId)
        );

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new GarSubscriptionDeleteException('Could not delete subscription', previous: $e);
        }
        return $statusCode === 204;
    }

    /**
     * @return GarReport[]
     * @throws GarCertificateConfigurationException
     * @throws GarReportListGetException
     */
    public function getReports(bool $notAcknowledged = true, bool $acknowledged = false): array
    {
        if (!$notAcknowledged && !$acknowledged) {
            return [];
        }

        if ($notAcknowledged && $acknowledged) {
            $status = GarReport::STATUS_ALL;
        } else {
            $status = $notAcknowledged ? GarReport::STATUS_NOT_ACKNOWLEDGED : GarReport::STATUS_ACKNOWLEDGED;
        }

        $response = $this->getReportClient()->request('GET', $this->getReportEndpoint(sprintf("/rapportsAffectation/%s/%s", $this->distributorId, $status)));
        try {
            $content = $response->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            throw new GarReportListGetException('Could not get report list contents', previous: $e);
        }

        $data = json_decode($content, true);

        return array_map(function ($data) {
            return (new GarReport())
                ->setName($data['nomRapport'])
                ->setTimestamp((DateTimeImmutable::createFromFormat('d/m/Y', $data['dateCreation']))->setTime(0, 0))
                ->setSize($data['taille'])
                ->setStatus($data['statut']);
        }, array_filter($data['rapportsAffectation'], function ($data) {
            return array_key_exists('statut', $data);
        }));
    }

    private function getGlobalReportDirectory(): string
    {
        $dir = $this->cacheDirectory . '/reports/global';

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    private function getTodayGlobalReportPath(): string
    {
        return $this->getGlobalReportDirectory() . '/' . date('Y-m-d') . '.xml';
    }

    /**
     * @throws GarCertificateConfigurationException
     * @throws GarGlobalReportDownloadException
     * @throws GarReportDownloadException
     */
    private function ensureLatestGlobalReport(): string
    {
        $todayPath = $this->getTodayGlobalReportPath();

        if (file_exists($todayPath)) {
            return $todayPath;
        }

        // Remove old files
        $oldFiles = (new Finder())->in($this->getGlobalReportDirectory())->files()->name('*.xml');
        foreach ($oldFiles as $oldFile) {
            unlink($oldFile->getRealPath());
        }

        // We don't care about the status filter because the global report is listed in all responses
        $response = $this->getReportClient()->request('GET', $this->getReportEndpoint(sprintf("/rapportsAffectation/%s/%s", $this->distributorId, GarReport::STATUS_ACKNOWLEDGED)));

        try {
            $content = $response->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            throw new GarGlobalReportDownloadException('Could not get global report contents', previous: $e);
        }

        $data = json_decode($content, true);

        $reportData = $data['rapportsAffectation'][count($data['rapportsAffectation']) - 1];

        $report = (new GarReport())
            ->setName($reportData['nomRapport'])
            ->setTimestamp((DateTimeImmutable::createFromFormat('d/m/Y', $reportData['dateCreation']))->setTime(0, 0))
            ->setSize($reportData['taille']);

        $xmlPath = $this->downloadReport($report);
        rename($xmlPath, $todayPath);

        return $todayPath;
    }

    /**
     * @throws GarCertificateConfigurationException
     * @throws GarReportDownloadException
     */
    private function downloadReport(string|GarReport $report): string
    {
        $reportIdentifier = is_string($report) ? $report : $report->getName();
        $response = $this->getReportClient()->request('GET', $this->getReportEndpoint(sprintf("/GAR-Affectations/%s/%s", $this->distributorId, $reportIdentifier)));
        $directory = $this->cacheDirectory . '/reports';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $zipPath = $directory . '/' . $reportIdentifier;

        try {
            $content = $response->getContent();
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $e) {
            throw new GarReportDownloadException('Could not download report', previous: $e);
        }

        file_put_contents($zipPath, $content);

        $zip = new ZipArchive();
        $zip->open($zipPath);

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[] = $zip->getNameIndex($i);
        }

        $zip->extractTo($directory);
        $zip->close();

        unlink($zipPath);

        return $directory . '/' . $files[0];
    }

    /**
     * @param array{resourceId?: string, uai?: string|string[]} $options
     * @return array{
     *     resource: array{
     *          id: string,
     *          title: string
     *      },
     *     subscription: array{
     *          id: string,
     *          end: DateTimeImmutable
     *      },
     *     uai: string
     * }[]
     *
     * @throws GarCertificateConfigurationException
     * @throws GarGlobalReportDownloadException
     * @throws GarReportDownloadException
     */
    public function getGlobalReportItems(array $options = []): array
    {
        $xml = simplexml_load_file($this->ensureLatestGlobalReport());

        $resolver = new OptionsResolver();
        $resolver->define('resourceId')->allowedTypes('string');
        $resolver->define('uai')->allowedTypes('string', 'string[]');

        $resolvedOptions = $resolver->resolve($options);

        $xpath = '//GARRessource';
        if (array_key_exists('resourceId', $resolvedOptions)) {
            $xpath .= "[@idRessource='$resolvedOptions[resourceId]']";
        }

        $xpath .= '/GARAbonnement/GAREtablissement';

        if (array_key_exists('uai', $resolvedOptions)/* && count($resolvedOptions['uai']) > 0*/) {

            /** @var string|string[] $uaiOption */
            $uaiOption = $resolvedOptions['uai'];

            /** @var string[] $uaiList */
            $uaiList = is_string($uaiOption) ? [$uaiOption] : $uaiOption;

            if (count($uaiList) > 0) {
                $selectors = array_map(function ($uai) {
                    return "@UAI='$uai'";
                }, $uaiList);

                $xpath .= "[" . implode(' or ', $selectors) . "]";
            }
        }

        /** @var SimpleXMLElement[] $institutions */
        $institutions = $xml->xpath($xpath);

        $items = [];

        foreach ($institutions as $institution) {
            $items[] = [
                'resource'       => [
                    'id'    => (string)$institution->xpath('../..')[0]['idRessource'],
                    'title' => (string)$institution->xpath('../..')[0]['titreRessource'],
                ],
                'subscription'   => [
                    'id'  => (string)$institution->xpath('..')[0]['idAbonnement'],
                    'end' => (DateTimeImmutable::createFromFormat('Y-m-d', (string)$institution->xpath('..')[0]['finValidite']))->setTime(0, 0),
                ],
                'uai'            => (string)$institution['UAI'],
                'numAssignments' => count($institution->xpath('./Affectation')),
            ];
        }

        return $items;
    }

}
