<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\Here;

use Geocoder\Collection;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\InvalidCredentials;
use Geocoder\Exception\QuotaExceeded;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Provider\Provider;
use Geocoder\Provider\Here\Model\HereAddress;
use Http\Client\HttpClient;

/**
 * @author Sébastien Barré <sebastien@sheub.eu>
 */
final class Here extends AbstractHttpProvider implements Provider
{
    /**
     * @var string
     */
    const GEOCODE_ENDPOINT_URL = 'https://geocode.search.hereapi.com/v1/geocode?apiKey=%s&q=%s';

    /**
     * @var string
     */
    const REVERSE_ENDPOINT_URL = 'https://revgeocode.search.hereapi.com/v1/revgeocode?apiKey=%s&at=%s,%s';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @param HttpClient $client An HTTP adapter.
     * @param string $apiKey
     */
    public function __construct(HttpClient $client, string $apiKey)
    {
        if (empty($apiKey)) {
            throw new InvalidCredentials('Invalid or missing api key.');
        }
        $this->apiKey = $apiKey;

        parent::__construct($client);
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        // This API doesn't handle IPs
        if (filter_var($query->getText(), FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The Here provider does not support IP addresses, only street addresses.');
        }

        $url = sprintf(self::GEOCODE_ENDPOINT_URL, $this->apiKey, rawurlencode($query->getText()));

        $fineSearch = null;

        if (null !== $query->getData('country')) {
            $fineSearch = sprintf('%scountry=%s', $this->buildAdditionalQuery($fineSearch), rawurlencode($query->getData('country')));
        }

        if (null !== $query->getData('state')) {
            $fineSearch = sprintf('%sstate=%s', $this->buildAdditionalQuery($fineSearch), rawurlencode($query->getData('state')));
        }

        if (null !== $query->getData('county')) {
            $fineSearch = sprintf('%scounty=%s', $this->buildAdditionalQuery($fineSearch), rawurlencode($query->getData('county')));
        }

        if (null !== $query->getData('city')) {
            $fineSearch = sprintf('%scity=%s', $this->buildAdditionalQuery($fineSearch), rawurlencode($query->getData('city')));
        }

        if (null !== $query->getLocale()) {
            $url = sprintf('%s&lang=%s', $url, $query->getLocale());
        }

        if (null !== $query->getData('limit')) {
            $url = sprintf('%s&limit=%s', $url, $query->getLocale());
        }

        if (null !== $fineSearch) {
            $url = sprintf('%s%s', $url, $fineSearch);
        }

        return $this->executeQuery($url, $query->getLimit());
    }

    private function buildAdditionalQuery($query = null)
    {
        return !is_null($query) ? sprintf('%s;', $query) : '&qq=';
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        $coordinates = $query->getCoordinates();
        $url = sprintf(self::REVERSE_ENDPOINT_URL, $this->apiKey, $coordinates->getLatitude(), $coordinates->getLongitude());

        if (null !== $query->getLimit()) {
            $url = sprintf('%s&limit=%s', $url, $query->getLimit());
        }

        if (null !== $query->getLocale()) {
            $url = sprintf('%s&lang=%s', $url, $query->getLocale());
        }

        return $this->executeQuery($url, $query->getLimit());
    }

    /**
     * @param string $url
     * @param int    $limit
     *
     * @return Collection
     */
    private function executeQuery(string $url, int $limit): Collection
    {
        $content = $this->getUrlContents($url);
        $json = json_decode($content, true);

        if (isset($json['error'])) {
            switch ($json['error']) {
                case 'Unauthorized':
                    throw new InvalidCredentials('Invalid or missing api key.');
                case 'QuotaExceeded':
                    throw new QuotaExceeded('Valid request but quota exceeded.');
                case 'InvalidCredentials':
                    throw new InvalidArgument('Input parameter validation failed.');
            }
        }

        if (!isset($json['items']) || empty($json['items'])) {
            return new AddressCollection([]);
        }

        $locations = $json['items'];

        foreach ($locations as $location) {
            $builder = new AddressBuilder($this->getName());
            $coordinates = $location['position'];
            $builder->setCoordinates($coordinates['lat'], $coordinates['lng']);
            $bounds = $location['mapView'];

            $builder->setBounds($bounds['south'], $bounds['west'], $bounds['north'], $bounds['east']);
            $builder->setStreetNumber($location['address']['houseNumber'] ?? null);
            $builder->setStreetName($location['address']['street'] ?? null);
            $builder->setPostalCode($location['address']['postalCode'] ?? null);
            $builder->setLocality($location['address']['city'] ?? null);
            $builder->setSubLocality($location['address']['district'] ?? null);
            $builder->setCountryCode($location['address']['countryCode'] ?? null);
            $builder->setCountry($location['address']['countryName'] ?? null);

            /** @var HereAddress $address */
            $address = $builder->build(HereAddress::class);
            $address = $address->withLocationId($location['id'] ?? null);
            $address = $address->withLocationType($location['resultType']);

            foreach (['distance', 'houseNumberType', 'addressBlockType', 'localityType', 'administrativeAreaType', 'houseNumberFallback'] as $key) {
                if (array_key_exists($key, $location)) {
                    $address = $address->addAdditionalData($key, $location[$key]);
                }
            }

            foreach (['label', 'subdistrict', 'block', 'subblock', 'state', 'county'] as $key) {

                if (array_key_exists($key, $location['address'])) {
                    $address = $address->addAdditionalData($key, $location['address'][$key]);
                }
            }

            $results[] = $address;

            if (count($results) >= $limit) {
                break;
            }
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'Here';
    }

    /**
     * Serialize the component query parameter.
     *
     * @param array $components
     *
     * @return string
     */
    private function serializeComponents(array $components): string
    {
        return implode(';', array_map(function ($name, $value) {
            return sprintf('%s,%s', $name, $value);
        }, array_keys($components), $components));
    }
}
