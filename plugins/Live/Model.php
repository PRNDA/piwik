<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Live;

use Exception;
use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Period;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Segment;
use Piwik\Site;

class Model
{

    /**
     * @param $idSite
     * @param $period
     * @param $date
     * @param $segment
     * @param $countVisitorsToFetch
     * @param $visitorId
     * @param $minTimestamp
     * @param $filterSortOrder
     * @return array
     * @throws Exception
     */
    public function queryLogVisits($idSite, $period, $date, $segment, $countVisitorsToFetch, $visitorId, $minTimestamp, $filterSortOrder)
    {
        list($sql, $bind) = $this->makeLogVisitsQueryString($idSite, $period, $date, $segment, $countVisitorsToFetch, $visitorId, $minTimestamp, $filterSortOrder);

        try {
            $data = Db::fetchAll($sql, $bind);
            return $data;
        } catch (Exception $e) {
            echo $e->getMessage();
            exit;
        }
        return $data;
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return array
     * @throws Exception
     */
    public function queryCounters($idSite, $lastMinutes, $segment)
    {
        $lastMinutes = (int)$lastMinutes;

        $counters = array(
            'visits' => 0,
            'actions' => 0,
            'visitors' => 0,
            'visitsConverted' => 0,
        );

        if (empty($lastMinutes)) {
            return array($counters);
        }

        list($whereIdSites, $idSites) = $this->getIdSitesWhereClause($idSite);

        $select = "count(*) as visits, COUNT(DISTINCT log_visit.idvisitor) as visitors";
        $where = $whereIdSites . "AND log_visit.visit_last_action_time >= ?";
        $bind = $idSites;
        $bind[] = Date::factory(time() - $lastMinutes * 60)->toString('Y-m-d H:i:s');

        $segment = new Segment($segment, $idSite);
        $query = $segment->getSelectQuery($select, 'log_visit', $where, $bind);

        $data = Db::fetchAll($query['sql'], $query['bind']);

        $counters['visits'] = $data[0]['visits'];
        $counters['visitors'] = $data[0]['visitors'];

        $select = "count(*)";
        $from = 'log_link_visit_action';
        list($whereIdSites) = $this->getIdSitesWhereClause($idSite, $from);
        $where = $whereIdSites . "AND log_link_visit_action.server_time >= ?";
        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $counters['actions'] = Db::fetchOne($query['sql'], $query['bind']);

        $select = "count(*)";
        $from = 'log_conversion';
        list($whereIdSites) = $this->getIdSitesWhereClause($idSite, $from);
        $where = $whereIdSites . "AND log_conversion.server_time >= ?";
        $query = $segment->getSelectQuery($select, $from, $where, $bind);
        $counters['visitsConverted'] = Db::fetchOne($query['sql'], $query['bind']);

        return array($counters);
    }



    /**
     * @param $idSite
     * @param string $table
     * @return array
     */
    private function getIdSitesWhereClause($idSite, $table = 'log_visit')
    {
        $idSites = array($idSite);
        Piwik::postEvent('Live.API.getIdSitesString', array(&$idSites));

        $idSitesBind = Common::getSqlStringFieldsArray($idSites);
        $whereClause = $table . ".idsite in ($idSitesBind) ";
        return array($whereClause, $idSites);
    }


    /**
     * Returns the ID of a visitor that is adjacent to another visitor (by time of last action)
     * in the log_visit table.
     *
     * @param int $idSite The ID of the site whose visits should be looked at.
     * @param string $visitorId The ID of the visitor to get an adjacent visitor for.
     * @param string $visitLastActionTime The last action time of the latest visit for $visitorId.
     * @param string $segment
     * @param bool $getNext Whether to retrieve the next visitor or the previous visitor. The next
     *                      visitor will be the visitor that appears chronologically later in the
     *                      log_visit table. The previous visitor will be the visitor that appears
     *                      earlier.
     * @return string The hex visitor ID.
     * @throws Exception
     */
    public function queryAdjacentVisitorId($idSite, $visitorId, $visitLastActionTime, $segment, $getNext)
    {
        if ($getNext) {
            $visitLastActionTimeCondition = "sub.visit_last_action_time <= ?";
            $orderByDir = "DESC";
        } else {
            $visitLastActionTimeCondition = "sub.visit_last_action_time >= ?";
            $orderByDir = "ASC";
        }

        $visitLastActionDate = Date::factory($visitLastActionTime);
        $dateOneDayAgo = $visitLastActionDate->subDay(1);
        $dateOneDayInFuture = $visitLastActionDate->addDay(1);

        $select = "log_visit.idvisitor, MAX(log_visit.visit_last_action_time) as visit_last_action_time";
        $from = "log_visit";
        $where = "log_visit.idsite = ? AND log_visit.idvisitor <> ? AND visit_last_action_time >= ? and visit_last_action_time <= ?";
        $whereBind = array($idSite, @Common::hex2bin($visitorId), $dateOneDayAgo->toString('Y-m-d H:i:s'), $dateOneDayInFuture->toString('Y-m-d H:i:s'));
        $orderBy = "MAX(log_visit.visit_last_action_time) $orderByDir";
        $groupBy = "log_visit.idvisitor";

        $segment = new Segment($segment, $idSite);
        $queryInfo = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy);

        $sql = "SELECT sub.idvisitor, sub.visit_last_action_time FROM ({$queryInfo['sql']}) as sub
                 WHERE $visitLastActionTimeCondition
                 LIMIT 1";
        $bind = array_merge($queryInfo['bind'], array($visitLastActionTime));

        $visitorId = Db::fetchOne($sql, $bind);
        if (!empty($visitorId)) {
            $visitorId = bin2hex($visitorId);
        }
        return $visitorId;
    }

    /**
     * @param $idSite
     * @param $period
     * @param $date
     * @param $segment
     * @param $countVisitorsToFetch
     * @param $visitorId
     * @param $minTimestamp
     * @param $filterSortOrder
     * @return array
     * @throws Exception
     */
    public function makeLogVisitsQueryString($idSite, $period, $date, $segment, $countVisitorsToFetch, $visitorId, $minTimestamp, $filterSortOrder)
    {
        $where = $whereBind = array();

        list($whereClause, $idSites) = $this->getIdSitesWhereClause($idSite);

        $where[] = $whereClause;
        $whereBind = $idSites;

        if (!empty($visitorId)) {
            $where[] = "log_visit.idvisitor = ? ";
            $whereBind[] = @Common::hex2bin($visitorId);
        }

        if (!empty($minTimestamp)) {
            $where[] = "log_visit.visit_last_action_time > ? ";
            $whereBind[] = date("Y-m-d H:i:s", $minTimestamp);
        }

        // If no other filter, only look at the last 24 hours of stats
        if (empty($visitorId)
            && empty($countVisitorsToFetch)
            && empty($period)
            && empty($date)
        ) {
            $period = 'day';
            $date = 'yesterdaySameTime';
        }

        // SQL Filter with provided period
        if (!empty($period) && !empty($date)) {
            $currentSite = $this->makeSite($idSite);
            $currentTimezone = $currentSite->getTimezone();

            $dateString = $date;
            if ($period == 'range') {
                $processedPeriod = new Range('range', $date);
                if ($parsedDate = Range::parseDateRange($date)) {
                    $dateString = $parsedDate[2];
                }
            } else {
                $processedDate = Date::factory($date);
                if ($date == 'today'
                    || $date == 'now'
                    || $processedDate->toString() == Date::factory('now', $currentTimezone)->toString()
                ) {
                    $processedDate = $processedDate->subDay(1);
                }
                $processedPeriod = Period\Factory::build($period, $processedDate);
            }
            $dateStart = $processedPeriod->getDateStart()->setTimezone($currentTimezone);
            $where[] = "log_visit.visit_last_action_time >= ?";
            $whereBind[] = $dateStart->toString('Y-m-d H:i:s');

            if (!in_array($date, array('now', 'today', 'yesterdaySameTime'))
                && strpos($date, 'last') === false
                && strpos($date, 'previous') === false
                && Date::factory($dateString)->toString('Y-m-d') != Date::factory('now', $currentTimezone)->toString()
            ) {
                $dateEnd = $processedPeriod->getDateEnd()->setTimezone($currentTimezone);
                $where[] = " log_visit.visit_last_action_time <= ?";
                $dateEndString = $dateEnd->addDay(1)->toString('Y-m-d H:i:s');
                $whereBind[] = $dateEndString;
            }
        }

        if (count($where) > 0) {
            $where = join("
				AND ", $where);
        } else {
            $where = false;
        }

        if (strtolower($filterSortOrder) !== 'asc') {
            $filterSortOrder = 'DESC';
        }
        $segment = new Segment($segment, $idSite);

        // Subquery to use the indexes for ORDER BY
        $select = "log_visit.*";
        $from = "log_visit";
        $groupBy = false;
        $limit = $countVisitorsToFetch >= 1 ? (int)$countVisitorsToFetch : 0;
        $orderBy = "idsite, visit_last_action_time " . $filterSortOrder;
        $orderByParent = "sub.visit_last_action_time " . $filterSortOrder;

        $subQuery = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy, $limit);

        $bind = $subQuery['bind'];
        // Group by idvisit so that a visitor converting 2 goals only appears once
        $sql = "
			SELECT sub.* FROM (
				" . $subQuery['sql'] . "
			) AS sub
			GROUP BY sub.idvisit
			ORDER BY $orderByParent
		";
        return array($sql, $bind);
    }

    /**
     * @param $idSite
     * @return Site
     */
    protected function makeSite($idSite)
    {
        return new Site($idSite);
    }
} 