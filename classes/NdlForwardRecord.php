<?php
/**
 * NdlForwardRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2016
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
require_once 'ForwardRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlForwardRecord Class
 *
 * ForwardRecord with NDL specific functionality
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NdlForwardRecord extends ForwardRecord
{
    /**
     * Default primary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $primaryAuthorRelators = [
        'A00', 'A03', 'A06', 'A50', 'A99',
    ];

    /**
     * Default secondary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $secondaryAuthorRelators = [
        'D01', 'D02', 'E01', 'F01', 'F02', 'ctb', 'rce'
    ];

    /**
     * Default corporate author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $corporateAuthorRelators = [
        'dst', 'prn', 'fnd', 'lbr'
    ];

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();

        if (isset($data['publishDate'])) {
            $year = MetadataUtils::extractYear($data['publishDate']);
            $data['main_date_str'] = $year;
            $data['main_date'] = $this->validateDate("$year-01-01T00:00:00Z");
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = metadataUtils::dateRangeToStr(
                    ["$year-01-01T00:00:00Z", "$year-12-31T23:59:59Z"]
                );
        }

        $data['publisher'] = $this->getPublishers();
        $data['genre'] = $this->getGenres();

        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        return $data;
    }

    /**
     * Get authors by relator codes
     *
     * @param array $relators Allowed relators
     *
     * @return array Array keyed by 'names' for author names, 'ids' for author ids
     * and 'relators' for relator codes
     */
    protected function getAuthorsByRelator($relators)
    {
        $result = ['names' => [], 'ids' => [], 'relators' => []];
        foreach ($this->getMainElement()->HasAgent as $agent) {
            $relator = $this->normalizeRelator((string)$agent->Activity);

            $attributes = $agent->Activity->attributes();
            if (!empty($attributes->{'elokuva-avustajat'})
                && $attributes->{'elokuva-avustajat'} == 'avustajat'
            ) {
                $relator = 'ctb';
            } elseif (!empty($attributes->{'elokuva-elolevittaja'})) {
                $relator = 'dst';
            } elseif (!empty($attributes->{'elokuva-elotuotantoyhtio'})) {
                continue;
            } elseif (!empty($attributes->{'elokuva-elorahoitusyhtio'})) {
                $relator = 'fnd';
            } elseif (!empty($attributes->{'elokuva-elolaboratorio'})) {
                $relator = 'lbr';
            } elseif (!empty($attributes->{'elokuva-elotekija-tehtava'})) {
                switch ($attributes->{'elokuva-elotekija-tehtava'}) {
                case 'äänitys':
                    $relator = 'rce';
                    break;
                }
            }

            if (!in_array($relator, $relators)) {
                continue;
            }

            $result['names'][] = (string)$agent->AgentName;
            $id = (string)$agent->AgentIdentifier->IDTypeName . ':'
                . (string)$agent->AgentIdentifier->IDValue;
            if ($id != ':') {
                $result['ids'][] = $id;
            }
            $result['relators'][] = $relator;
        }

        return $result;
    }

    /**
     * Return publishers
     *
     * @return array
     */
    protected function getPublishers()
    {
        $result = [];
        foreach ($this->getMainElement()->HasAgent as $agent) {
            $attributes = $agent->Activity->attributes();
            if (!empty($attributes->{'elokuva-elotuotantoyhtio'})) {
                $result[] = (string)$agent->AgentName;
            }
        }
        return $result;
    }

    /**
     * Return genres
     *
     * @return array
     */
    protected function getGenres()
    {
        return [$this->getProductionEventAttribute('elokuva-genre')];
    }

    /**
     * Return publication year/date range
     *
     * @return array|null
     */
    protected function getPublicationDateRange()
    {
        $year = $this->getPublicationYear();
        if ($year) {
            $startDate = "$year-01-01T00:00:00Z";
            $endDate = "$year-12-31T23:59:59Z";
            return [$startDate, $endDate];
        }
        return null;
    }

    /**
     * Return a production event attribute
     *
     * @param string $attribute Attribute name
     *
     * @return string
     */
    protected function getProductionEventAttribute($attribute)
    {
        foreach ($this->getMainElement()->ProductionEvent as $event) {
            $attributes = $event->ProductionEventType->attributes();
            if (!empty($attributes{$attribute})) {
                return (string)$attributes{$attribute};
            }
        }
        return '';
    }
}
