<?php

namespace App\Repository;

use App\Entity\Tournament;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\QueryBuilder;
use PDO;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method Tournament|null find($id, $lockMode = null, $lockVersion = null)
 * @method Tournament|null findOneBy(array $criteria, array $orderBy = null)
 * @method Tournament[]    loadAll()
 * @method Tournament[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TournamentRepository extends ServiceEntityRepository
{
    /** @var Connection  */
    private $connection;

    public function __construct(RegistryInterface $registry, Connection $connection)
    {
        parent::__construct($registry, Tournament::class);
        $this->connection = $connection;
    }

    /**
     * @return mixed
     */
    public function loadList()
    {
        $sql =
            'select t.id, t.name, t.start_time, t.is_playoffs as isPlayoffs, t.office_id as officeId, ' .
            'case when t.is_playoffs = 0 then \'group\' else \'playoffs\' end as phase, ' .
            't.is_finished as isFinished, count(distinct(g.home_player_id)) as participants, ' .
            'count(g.id) as scheduled, if (sum(g.is_finished) is null, 0, sum(g.is_finished)) as finished ' .
            'from tournament t ' .
            'left join game g on g.tournament_id = t.id ' .
            'where t.is_official = 1 ' .
            'group by t.id';

        $em = $this->getEntityManager();
        $stmt = $em->getConnection()->prepare($sql);
        $stmt-> execute();

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $result;
    }

    /**
     * @return Tournament[]|null
     */
    public function loadCurrentTournaments(): ? array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.is_finished = 0 and t.is_playoffs = 0')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Tournament|null
     */
    public function loadCurrentPlayoffsTournament(): ?Tournament
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.is_finished = 0 and t.is_playoffs = 1')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param $tournamentId
     * @return array
     */
    public function getStandingsByTournamentId($tournamentId)
    {
        $sql =
            'select p.id as playerId, p.name as playerName, ptg.group_id as groupId, tg.name as groupName, tg.color_template as colorTemplate, ' .
            'SUM(if (g1.is_finished = 1, 1, 0)) as played, tg.abbreviation as groupAbbreviation, ' .
            'SUM(if (g1.winner_id = ptg.player_id, 1, 0)) as wins, ' .
            'SUM(if (g1.is_finished = 1 AND g1.winner_id = 0, 1, 0)) as draws, ' .
            'SUM(if (g1.is_finished = 1 AND g1.winner_id != 0 AND g1.winner_id != ptg.player_id, 1, 0)) as losses, ' .
            '(SUM(if (g1.winner_id = ptg.player_id, 1, 0)) * 2 + SUM(if (g1.is_finished = 1 AND g1.winner_id = 0, 1, 0))) as points, ' .
            '(SUM(if (g1.home_player_id = ptg.player_id, g1.home_score, 0)) + SUM(if (g1.away_player_id = ptg.player_id, g1.away_score, 0))) as setsFor, ' .
            '(SUM(if (g1.home_player_id = ptg.player_id, g1.away_score, 0)) + SUM(if (g1.away_player_id = ptg.player_id, g1.home_score, 0))) as setsAgainst, ' .
            '((SUM(if (g1.home_player_id = ptg.player_id, g1.home_score, 0)) + SUM(if (g1.away_player_id = ptg.player_id, g1.away_score, 0))) - ' .
            '(SUM(if (g1.home_player_id = ptg.player_id, g1.away_score, 0)) + SUM(if (g1.away_player_id = ptg.player_id, g1.home_score, 0)))) as setDf, ' .
            'u.ralliesFor, u.ralliesAgainst, u.df ' .
            'from player_tournament_group ptg ' .
            'left join game g1 on (g1.home_player_id = ptg.player_id or g1.away_player_id = ptg.player_id) and g1.tournament_id = :tournamentId ' .
            'left join player p on p.id = ptg.player_id ' .
            'left join tournament_group tg on tg.id = ptg.group_id ' .
            'left join ( ' .
            'select player, sum(pointsFor) as ralliesFor, sum(pointsAgainst) as ralliesAgainst, (sum(pointsFor) - sum(pointsAgainst)) as df from ( ' .
            'SELECT g.id, g.home_player_id   AS player, sum(s.home_points) AS pointsFor, sum(s.away_points) AS pointsAgainst ' .
            'FROM scores s JOIN game g ON g.id = s.game_id ' .
            'JOIN tournament t1 on t1.id = g.tournament_id WHERE t1.id = :tournamentId ' .
            'GROUP BY g.home_player_id ' .
            'UNION ' .
            'SELECT ' .
            'g.id, g.away_player_id   AS player, sum(s.away_points) AS pointsFor, sum(s.home_points) AS pointsAgainst ' .
            'FROM scores s JOIN game g ON g.id = s.game_id ' .
            'JOIN tournament t2 on t2.id = g.tournament_id WHERE t2.id = :tournamentId ' .
            'GROUP BY g.away_player_id ' .
            ') u group by player ' .
            ') u on u.player = ptg.player_id ' .
            'where ptg.tournament_id = :tournamentId ' .
            'group by ptg.player_id ' .
            'order by ptg.group_id asc, points desc, setDf desc, u.df desc ';

        $params['tournamentId'] = $tournamentId;

        $em = $this->getEntityManager();
        $stmt = $em->getConnection()->prepare($sql);
        $stmt-> execute($params);

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($result as $item) {
            $groupId = $item['groupId'];
            $groupName = $item['groupName'];
            $colorTemplate = explode('.', $item['colorTemplate']);
            $groupAbbreviation = $item['groupAbbreviation'];
            $playerId = $item['playerId'];
            $playerName = $item['playerName'];

            $played = $item['played'];
            $wins = $item['wins'];
            $draws = $item['draws'];
            $losses = $item['losses'];
            $points = $item['points'];
            $setsFor = $item['setsFor'];
            $setsAgainst = $item['setsAgainst'];
            $setsDiff = $setsFor - $setsAgainst;
            $ralliesFor = $item['ralliesFor'];
            $ralliesAgainst = $item['ralliesAgainst'];
            $ralliesDiff = $ralliesFor - $ralliesAgainst;

            $currentGroup = array_key_exists($groupId, $groups) ?
                $groups[$groupId] :
                $groups[$groupId] = [
                    'groupId' => $groupId,
                    'groupName' => $groupName,
                    'groupAbbreviation' => $groupAbbreviation,
                ];

            if (array_key_exists('players', $currentGroup)) {
                $pos = count($currentGroup['players']) + 1;
            } else {
                $pos = 1;
            }

            $playerPositionColor = $colorTemplate[$pos - 1];

            $currentGroup['players'][] = [
                'pos' => $pos,
                'posColor' => $playerPositionColor,
                'playerId' => $playerId,
                'playerName' => $playerName,
                'played' => $played,
                'wins' => $wins,
                'draws' => $draws,
                'losses' => $losses,
                'points' => $points,
                'setsFor' => $setsFor,
                'setsAgainst' => $setsAgainst,
                'setsDiff' => $setsDiff > 0 ? '+' . $setsDiff : (string)$setsDiff,
                'ralliesFor' => $ralliesFor,
                'ralliesAgainst' => $ralliesAgainst,
                'ralliesDiff' => $ralliesDiff > 0 ? '+' . $ralliesDiff : (string)$ralliesDiff,
            ];

            $groups[$groupId] = $currentGroup;
        }

        return $groups;
    }

    public function loadGroupsByPlayoffsId($tournamentId)
    {
        $query = 'select id, name from tournament_group tg where tg.tournament_id = :tournamentId';
        $em = $this->getEntityManager();
        $stmt = $em->getConnection()->prepare($query);
        $stmt-> execute([
            'tournamentId' => $tournamentId
        ]);

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $result;
    }

    protected function baseQueryLeadersPoints()
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                "p.id",
                "p.name",
                "p.office_id as officeId",
                "sum(if(p.id = g.home_player_id, s.home_points, s.away_points)) as points",
                "count(s.id) as sets",
                "(sum(if(p.id = g.home_player_id, s.home_points, s.away_points)) / count(s.id)) as avgps"
            )
            ->from("scores", "s")
            ->join("s", "game", "g", "s.game_id = g.id")
            ->join("g", "player", "p", "p.id in (g.home_player_id, g.away_player_id)")
            ->join("g", "tournament", "t", "g.tournament_id = t.id")
            ->andWhere("t.is_official = 1")
            ->andWhere("g.is_walkover = 0")
            ->andWhere("g.is_finished = 1")
            ->groupBy("p.id")
            ->orderBy("points desc, p.name")
            ->setMaxResults(5);

            return $qb;
    }

    public function baseQueryAvgAdvantage()
    {
        $query = $this->connection->createQueryBuilder()
            ->select(
                "avg(if(p.id = g.home_player_id, s.home_points - s.away_points, s.away_points - s.home_points)) as avgDiff",
                "p.id",
                "p.name",
                "p.office_id as officeId"
            )
            ->from("player", "p")
            ->join("p", "game", "g", "p.id in (g.home_player_id, g.away_player_id) and g.is_finished = 1 and g.is_walkover = 0")
            ->join("g", "scores", "s", "g.id = s.game_id")
            ->join("g", "tournament", "t", "g.tournament_id = t.id and t.is_official = 1")
            ->groupBy("p.id")
            ->orderBy("1", "desc")
            ->setMaxResults(5);

        return $query;
    }

    /**
     * @param $tournamentIds
     * @return array
     * @throws DBALException
     */
    public function loadLeaders($tournamentIds)
    {
        $em = $this->getEntityManager();
        $params = ['tournamentIds' => $tournamentIds];
        $types = ['tournamentIds' => Connection::PARAM_INT_ARRAY];

        $result = [];

        $data = $this->baseQueryLeadersPoints()
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
        $result['allTimePointsLeaders'] = $data;

        $data = $this->baseQueryLeadersPoints()
            ->andWhere("t.id in (:tournamentIds)")
            ->setParameter("tournamentIds", $tournamentIds, Connection::PARAM_INT_ARRAY)
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
        $result['currentTournamentPointsLeaders'] = $data;

        $data = $this->baseQueryLeadersPoints()
            ->andWhere("g.date_played between subdate(curdate(),dayofweek(curdate())+5) and subdate(curdate(),dayofweek(curdate())-1)")
            ->andWhere("t.id in (:tournamentIds)")
            ->setParameter("tournamentIds", $tournamentIds, Connection::PARAM_INT_ARRAY)
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
        $result['lastWeekPointsLeaders'] = $data;

        $data = $this->baseQueryAvgAdvantage()
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
        $result['avgDiffAllTime'] = $data;

        $data = $this->baseQueryAvgAdvantage()
            ->andWhere("t.id in (:tournamentIds)")
            ->setParameter("tournamentIds", $tournamentIds, Connection::PARAM_INT_ARRAY)
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
        $result['avgDiffCurrentTournament'] = $data;

        $data = $this->baseQueryAvgAdvantage()
            ->andWhere("g.date_played between subdate(curdate(),dayofweek(curdate())+5) and subdate(curdate(),dayofweek(curdate())-1)")
            ->andWhere("t.id in (:tournamentIds)")
            ->setParameter("tournamentIds", $tournamentIds, Connection::PARAM_INT_ARRAY)
            ->execute()
            ->fetchAll(PDO::FETCH_ASSOC);
        $result['avgDiffLastWeek'] = $data;

        $sql = "select p.id, p.name, p.tournament_elo as elo, p.office_id as officeId
                from player p
                order by tournament_elo desc
                limit 0,5";

        $stmt = $em->getConnection()->prepare($sql);
        $stmt-> execute([]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['eloLeaders'] = $data;

        $sql = "select * from (
                  select p.id, p.office_id as officeId,
                         p.name,
                         sum(if(p.id = g.home_player_id, (g.new_home_elo - g.old_home_elo),
                                (g.new_away_elo - g.old_away_elo))) as elodiff,
                                p.tournament_elo as telo
                  from game g
                           join player p on p.id in (g.home_player_id, g.away_player_id) and g.is_finished = 1 and
                                            g.is_walkover = 0
                  where g.tournament_id IN (:tournamentIds)
                  group by p.id
                  order by g.date_played asc
                ) x
                order by x.elodiff desc
                limit 0,5";

        $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['eloLeadersTournament'] = $data;

        $sql = "select * from (
                  select p.id, p.office_id as officeId,
                         p.name,
                         sum(if(p.id = g.home_player_id, (g.new_home_elo - g.old_home_elo),
                                (g.new_away_elo - g.old_away_elo))) as elodiff,
                                p.tournament_elo as telo
                  from game g
                           join player p on p.id in (g.home_player_id, g.away_player_id) and g.is_finished = 1 and
                                            g.is_walkover = 0
                  where g.tournament_id IN (:tournamentIds)
                  and g.date_played between subdate(curdate(),dayofweek(curdate())+5) and subdate(curdate(),dayofweek(curdate())-1)
                  group by p.id
                  order by g.date_played asc
                ) x
                order by x.elodiff desc
                limit 0,5";

        $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['eloLeadersLastWeek'] = $data;

        return $result;
    }

    public function loadWeekStats($tournamentIds)
    {
        $em = $this->getEntityManager();
        $params = ['tournamentIds' => $tournamentIds];
        $types = ['tournamentIds' => Connection::PARAM_INT_ARRAY];

        $result = [];

        $sql = "select g.home_player_id homePlayerId, g.away_player_id awayPlayerId, g.home_score homeScore, g.away_score awayScore, 
                sum(s.home_points) sumHomePoints, sum(s.away_points) sumAwayPoints, 
                (g.home_score + g.away_score) as setsCount,
                (sum(s.home_points) + sum(s.away_points)) as totalPoints, g.office_id as officeId,
                if (g.home_score = 0 or g.away_score = 0, 1, 0) as score30,
                if (g.home_score = 1 or g.away_score = 1, 1, 0) as score31,
                if (g.home_score = 2 or g.away_score = 2, 1, 0) as score22,
                if (pts.pointId is not null, 1, 0) as scouted
                from game g
                join scores s on g.id = s.game_id
                left join (
                    select p.id pointId, g2.id as gameId from
                    points p
                    join scores s2 on p.score_id = s2.id
                    join game g2 on s2.game_id = g2.id
                    group by g2.id
                    ) pts on g.id = pts.gameId
                where g.date_played between subdate(curdate(),dayofweek(curdate())+5)
                and subdate(curdate(),dayofweek(curdate())-1)
                and tournament_id in (:tournamentIds)
                group by g.id";

        $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $playersCache = [];

        foreach ($data as $item) {
            $officeId = $item['officeId'];
            if (!array_key_exists($officeId, $result)) {
                $result[$officeId] = [
                    'gamesCount' => 0,
                    'setsCount' => 0,
                    'totalPoints' => 0,
                    'score30' => 0,
                    'score31' => 0,
                    'score22' => 0,
                    'scouted' => 0,
                ];
                $playersCache[$officeId] = [];
            }

            if (!in_array($item['homePlayerId'], $playersCache[$officeId])) {
                $playersCache[$officeId][] = $item['homePlayerId'];
            }
            if (!in_array($item['awayPlayerId'], $playersCache[$officeId])) {
                $playersCache[$officeId][] = $item['awayPlayerId'];
            }

            $result[$officeId]['playersCount'] = count($playersCache[$officeId]);
            $result[$officeId]['gamesCount']++;
            $result[$officeId]['score30'] += $item['score30'];
            $result[$officeId]['score31'] += $item['score31'];
            $result[$officeId]['score22'] += $item['score22'];
            $result[$officeId]['setsCount'] += $item['setsCount'];
            $result[$officeId]['totalPoints'] += $item['totalPoints'];
            $result[$officeId]['scouted'] += $item['scouted'];
            $result[$officeId]['scoutedPercentage'] = number_format(($result[$officeId]['scouted'] / $result[$officeId]['gamesCount']) * 100, 2);
        }

        $sql = "select * from (
                  select p.id, p.office_id as officeId,
                         p.name,
                         sum(if(p.id = g.home_player_id, (g.new_home_elo - g.old_home_elo),
                                (g.new_away_elo - g.old_away_elo))) as elodiff,
                                p.tournament_elo as telo
                  from game g
                           join player p on p.id in (g.home_player_id, g.away_player_id) and g.is_finished = 1 and
                                            g.is_walkover = 0
                  where g.tournament_id IN (:tournamentIds)
                  and g.date_played between subdate(curdate(),dayofweek(curdate())+5) and subdate(curdate(),dayofweek(curdate())-1)
                  group by p.id
                  order by g.date_played asc
                ) x
                order by x.elodiff asc";

        $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
        $eloData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($eloData as $item) {
            $officeId = $item['officeId'];
            $playerName = $item['name'];
            $eloDiff = $item['elodiff'];

            $result[$officeId]['eloPlusPlayerName'] = $playerName;
            $result[$officeId]['eloPlusValue'] = $eloDiff;
        }

        $sql = "select * from (
                  select p.id, p.office_id as officeId,
                         p.name,
                         sum(if(p.id = g.home_player_id, (g.new_home_elo - g.old_home_elo),
                                (g.new_away_elo - g.old_away_elo))) as elodiff,
                                p.tournament_elo as telo
                  from game g
                           join player p on p.id in (g.home_player_id, g.away_player_id) and g.is_finished = 1 and
                                            g.is_walkover = 0
                  where g.tournament_id IN (:tournamentIds)
                  and g.date_played between subdate(curdate(),dayofweek(curdate())+5) and subdate(curdate(),dayofweek(curdate())-1)
                  group by p.id
                  order by g.date_played asc
                ) x
                order by x.elodiff desc";

        $stmt = $em->getConnection()->executeQuery($sql, $params, $types);
        $eloData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($eloData as $item) {
            $officeId = $item['officeId'];
            $playerName = $item['name'];
            $eloDiff = $item['elodiff'];

            $result[$officeId]['eloMinusPlayerName'] = $playerName;
            $result[$officeId]['eloMinusValue'] = $eloDiff;
        }

        return $result;
    }
}
