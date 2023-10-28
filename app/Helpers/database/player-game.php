<?php

use App\Platform\Enums\AchievementFlag;
use App\Platform\Enums\AchievementType;
use App\Platform\Enums\UnlockMode;
use App\Platform\Models\Achievement;
use App\Platform\Models\Game;
use App\Platform\Models\PlayerGame;
use App\Platform\Models\PlayerSession;
use App\Site\Enums\Permissions;
use App\Site\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @deprecated TODO read from PlayerGame model, badge eligibility checks moved to UpdatePlayerGameBadgeMetrics
 */
function testBeatenGame(int $gameId, string $user): array
{
    // First, get the count of beaten-tier achievements for the game.
    // We'll use this to determine if the game is even beatable, and later
    // use it to determine if the user has in fact beaten the game.
    $gameTierAchievementCounts = Achievement::where('GameID', $gameId)
        ->whereIn('type', [AchievementType::Progression, AchievementType::WinCondition])
        ->where('Flags', AchievementFlag::OfficialCore)
        ->select(['type', DB::raw('count(*) as total')])
        ->groupBy('type')
        ->get()
        ->keyBy('type')
        ->transform(function ($item) {
            return $item->total;
        });

    $totalProgressions = (int) ($gameTierAchievementCounts[AchievementType::Progression] ?? 0);
    $totalWinConditions = (int) ($gameTierAchievementCounts[AchievementType::WinCondition] ?? 0);

    // If the game has no beaten-tier achievements assigned, it is not considered beatable.
    // Bail.
    if ($totalProgressions === 0 && $totalWinConditions === 0) {
        // TODO use $playerGame->achievements_beat for isBeatable, remove rest
        return [
            'isBeatenSoftcore' => false,
            'isBeatenHardcore' => false,
            'isBeatable' => false,
        ];
    }

    // We can now start checking if the user has beaten the game.
    // Start by querying for their unlocked beaten-tier achievements.
    $userAchievements = Achievement::where('Achievements.GameID', $gameId)
        ->whereIn('Achievements.type', [AchievementType::Progression, AchievementType::WinCondition])
        ->where('Achievements.Flags', AchievementFlag::OfficialCore)
        ->leftJoin('Awarded', function ($join) use ($user) {
            $join->on('Achievements.ID', '=', 'Awarded.AchievementID')
                ->where('Awarded.User', '=', $user);
        })
        ->addSelect(['Achievements.type', 'Awarded.HardcoreMode', 'Awarded.AchievementID', 'Awarded.Date'])
        ->orderByDesc('Awarded.Date')
        ->get(['Achievements.type', 'Awarded.HardcoreMode', 'Awarded.AchievementID', 'Awarded.Date']);

    $numUnlockedSoftcoreProgressions = $userAchievements->where('type', AchievementType::Progression)->whereNotNull('Date')->where('HardcoreMode', UnlockMode::Softcore)->count();
    $numUnlockedHardcoreProgressions = $userAchievements->where('type', AchievementType::Progression)->whereNotNull('Date')->where('HardcoreMode', UnlockMode::Hardcore)->count();
    $numUnlockedSoftcoreWinConditions = $userAchievements->where('type', AchievementType::WinCondition)->whereNotNull('Date')->where('HardcoreMode', UnlockMode::Softcore)->count();
    $numUnlockedHardcoreWinConditions = $userAchievements->where('type', AchievementType::WinCondition)->whereNotNull('Date')->where('HardcoreMode', UnlockMode::Hardcore)->count();

    // If there are no Win Condition achievements in the set, the game is considered beaten
    // if the user unlocks all the progression achievements.
    $neededWinConditionAchievements = $totalWinConditions >= 1 ? 1 : 0;

    $isBeatenSoftcore =
        $numUnlockedSoftcoreProgressions === $totalProgressions
        && $numUnlockedSoftcoreWinConditions >= $neededWinConditionAchievements;

    $isBeatenHardcore =
        $numUnlockedHardcoreProgressions === $totalProgressions
        && $numUnlockedHardcoreWinConditions >= $neededWinConditionAchievements;

    // TODO use $playerGame->beaten_at for isBeatenSoftcore, $playerGame->beaten_hardcore_at for isBeatenHardcore, remove rest
    return [
        'isBeatenSoftcore' => $isBeatenSoftcore,
        'isBeatenHardcore' => $isBeatenHardcore,
        'isBeatable' => true,
    ];
}

function getGameRankAndScore(int $gameID, User $user): array
{
    if (empty($gameID)) {
        return [];
    }

    $dateClause = greatestStatement(['pg.last_unlock_hardcore_at', 'pg.last_unlock_at']);
    $rankClause = "ROW_NUMBER() OVER (ORDER BY pg.Points DESC, $dateClause ASC) UserRank";
    $untrackedClause = "AND ua.Untracked = 0";
    if ($user->Untracked) {
        $rankClause = "NULL AS UserRank";
        $untrackedClause = "";
    }

    $query = "WITH data
    AS (SELECT ua.User, $rankClause, pg.Points AS TotalScore, $dateClause AS LastAward
        FROM player_games AS pg
        INNER JOIN UserAccounts AS ua ON ua.ID = pg.user_id
        WHERE pg.game_id = $gameID $untrackedClause
        GROUP BY ua.User
        ORDER BY TotalScore DESC, LastAward ASC
   ) SELECT * FROM data WHERE User = :username";

    return legacyDbFetchAll($query, ['username' => $user->User])->toArray();
}

function getUserProgress(User $user, array $gameIDs, int $numRecentAchievements = -1, bool $withGameInfo = false): array
{
    $libraryOut = [];

    $awardedData = [];
    $gameInfo = [];
    $unlockedAchievements = [];
    $lockedAchievements = [];

    foreach ($gameIDs as $gameID) {
        $game = Game::with('system')->find($gameID);
        if (!$game) {
            $awardedData[$gameID] = [
                'NumPossibleAchievements' => 0,
                'PossibleScore' => 0,
                'NumAchieved' => 0,
                'ScoreAchieved' => 0,
                'NumAchievedHardcore' => 0,
                'ScoreAchievedHardcore' => 0,
            ];
            continue;
        }

        $playerGame = PlayerGame::where('user_id', '=', $user->ID)
            ->where('game_id', $gameID)
            ->first();

        $awardedData[$gameID] = [
            'NumPossibleAchievements' => $game->achievements_published ?? 0,
            'PossibleScore' => $game->points_total ?? 0,
            'NumAchieved' => $playerGame ? ($playerGame->achievements_unlocked ?? 0) : 0,
            'ScoreAchieved' => $playerGame ? ($playerGame->points ?? 0) : 0,
            'NumAchievedHardcore' => $playerGame ? ($playerGame->achievements_unlocked_hardcore ?? 0) : 0,
            'ScoreAchievedHardcore' => $playerGame ? ($playerGame->points_hardcore ?? 0) : 0,
        ];

        if ($withGameInfo) {
            $gameInfo[$gameID] = [
                'ID' => $game->ID,
                'Title' => $game->Title,
                'ConsoleID' => (int) $game->system->ID,
                'ConsoleName' => $game->system->Name,
                'ForumTopicID' => (int) $game->ForumTopicID,
                'Flags' => (int) $game->Flags,
                'ImageIcon' => $game->ImageIcon,
                'ImageTitle' => $game->ImageTitle,
                'ImageIngame' => $game->ImageIngame,
                'ImageBoxArt' => $game->ImageBoxArt,
                'Publisher' => $game->Publisher,
                'Developer' => $game->Developer,
                'Genre' => $game->Genre,
                'Released' => $game->Released,
                'IsFinal' => (int) $game->IsFinal,
            ];
        }

        if ($numRecentAchievements >= 0) {
            $gameData = $game->toArray();

            $achievements = $game->achievements()->published()
                ->leftJoin('player_achievements', 'player_achievements.achievement_id', '=', 'Achievements.ID')
                ->where('player_achievements.user_id', $user->id);
            foreach ($achievements->get() as $achievement) {
                if ($achievement->unlocked_hardcore_at) {
                    $unlockedAchievements[] = [
                        'Achievement' => $achievement->toArray(),
                        'When' => $achievement->unlocked_hardcore_at,
                        'Hardcore' => 1,
                        'Game' => $gameData,
                    ];
                } elseif ($achievement->unlocked_at) {
                    $unlockedAchievements[] = [
                        'Achievement' => $achievement->toArray(),
                        'When' => $achievement->unlocked_at,
                        'Hardcore' => 0,
                        'Game' => $gameData,
                    ];
                } else {
                    $lockedAchievements[] = [
                        'Achievement' => $achievement->toArray(),
                        'Game' => $gameData,
                    ];
                }
            }
        }
    }
    $libraryOut['Awarded'] = $awardedData;

    if ($withGameInfo) {
        $libraryOut['GameInfo'] = $gameInfo;
    }

    // magic numbers!
    // -1 = don't populate RecentAchievements field
    // 0 = return all achievements for each game, with the unlocked achievements first ordered by unlock date
    // >0 = return the N most recent unlocks across all games queried, grouped by game
    if ($numRecentAchievements >= 0) {
        usort($unlockedAchievements, function ($a, $b) {
            if ($a['When'] == $b['When']) {
                return $a['Achievement']['ID'] <=> $b['Achievement']['ID'];
            }

            return -($a['When'] <=> $b['When']);
        });

        if ($numRecentAchievements !== 0) {
            $unlockedAchievements = array_slice($unlockedAchievements, 0, $numRecentAchievements);
        }

        $recentAchievements = [];

        foreach ($unlockedAchievements as $unlockedAchievement) {
            $gameData = $unlockedAchievement['Game'];
            $gameID = (int) $gameData['ID'];
            $achievementData = $unlockedAchievement['Achievement'];
            $achievementID = (int) $achievementData['ID'];

            $recentAchievements[$gameID][$achievementID] = [
                'ID' => $achievementID,
                'GameID' => $gameID,
                'GameTitle' => $gameData['Title'],
                'Title' => $achievementData['Title'],
                'Description' => $achievementData['Description'],
                'Points' => (int) $achievementData['Points'],
                'BadgeName' => $achievementData['BadgeName'],
                'IsAwarded' => '1',
                'DateAwarded' => $unlockedAchievement['When'],
                'HardcoreAchieved' => (int) $unlockedAchievement['Hardcore'],
            ];
        }

        if ($numRecentAchievements === 0) {
            foreach ($lockedAchievements as $lockedAchievement) {
                $gameData = $lockedAchievement['Game'];
                $gameID = (int) $gameData['ID'];
                $achievementData = $lockedAchievement['Achievement'];
                $achievementID = (int) $achievementData['ID'];

                $recentAchievements[$gameID][$achievementID] = [
                    'ID' => $achievementID,
                    'GameID' => $gameID,
                    'GameTitle' => $gameData['Title'],
                    'Title' => $achievementData['Title'],
                    'Description' => $achievementData['Description'],
                    'Points' => (int) $achievementData['Points'],
                    'BadgeName' => $achievementData['BadgeName'],
                    'IsAwarded' => '0',
                    'DateAwarded' => null,
                    'HardcoreAchieved' => null,
                ];
            }
        }

        $libraryOut['RecentAchievements'] = $recentAchievements;
    }

    return $libraryOut;
}

function getUserAchievementUnlocksForGame(User|string $user, int $gameID, int $flag = AchievementFlag::OfficialCore): array
{
    $user = is_string($user) ? User::firstWhere('User', $user) : $user;

    $playerAchievements = $user
        ->playerAchievements()
        ->join('Achievements', 'Achievements.ID', '=', 'achievement_id')
        ->where('GameID', $gameID)
        ->where('Flags', $flag)
        ->get([
            'achievement_id',
            'unlocked_at',
            'unlocked_hardcore_at',
        ])
        ->mapWithKeys(function ($unlock, int $key) {
            $result = [];

            // TODO move this transformation to where it's needed (web api) and use models everywhere else
            if ($unlock->unlocked_at) {
                $result['DateEarned'] = $unlock->unlocked_at->__toString();
            }

            if ($unlock->unlocked_hardcore_at) {
                $result['DateEarnedHardcore'] = $unlock->unlocked_hardcore_at->__toString();
            }

            return [$unlock->achievement_id => $result];
        });

    return $playerAchievements->toArray();
}

function GetAllUserProgress(User $user, int $consoleID): array
{
    $retVal = [];

    $query = "SELECT gd.ID, gd.achievements_published AS NumAch,
                     COALESCE(pg.achievements_unlocked, 0) AS Earned,
                     COALESCE(pg.achievements_unlocked_hardcore, 0) AS HCEarned
            FROM GameData AS gd
            LEFT JOIN player_games pg ON pg.game_id = gd.ID AND pg.user_id={$user->id}
            WHERE gd.achievements_published > 0 AND gd.ConsoleID = $consoleID";

    foreach (legacyDbFetchAll($query) as $row) {
        $id = $row['ID'];
        unset($row['ID']);

        $retVal[$id] = $row;
    }

    return $retVal;
}

function getUsersGameList(User $user): array
{
    $dataOut = [];

    $query = "SELECT gd.ID, gd.Title, c.Name AS ConsoleName,
                     gd.achievements_published AS NumAchievements,
                     pg.achievements_unlocked AS NumAchieved
              FROM player_games pg
              INNER JOIN GameData AS gd ON gd.ID = pg.game_id
              INNER JOIN Console AS c ON c.ID = gd.ConsoleID
              WHERE pg.user_id = {$user->id}
              AND pg.achievements_unlocked > 0";

    foreach (legacyDbFetchAll($query) as $row) {
        $dataOut[$row['ID']] = $row;
    }

    return $dataOut;
}

/**
 * @deprecated TODO: Remove when denormalized data is ready. See comments in getUsersCompletedGamesAndMax().
 */
function getLightweightUsersCompletedGamesAndMax(string $user, string $cachedAwardedValues): array
{
    // Parse the cached value.
    $awardedCache = [];
    foreach (explode(',', $cachedAwardedValues) as $row) {
        list($gameId, $maxPossible, $numAwarded, $numAwardedHC, $mostRecentWonDate, $firstWonDate) = explode('|', $row);

        $awardedCache[$gameId] = [
            'MaxPossible' => $maxPossible,
            'NumAwarded' => $numAwarded,
            'NumAwardedHC' => $numAwardedHC,
            'MostRecentWonDate' => $mostRecentWonDate,
            'FirstWonDate' => $firstWonDate,
        ];
    }

    $lightQuery = "SELECT gd.ID AS GameID, c.Name AS ConsoleName, c.ID AS ConsoleID, gd.ImageIcon, gd.Title
    FROM GameData AS gd
    LEFT JOIN Console AS c ON c.ID = gd.ConsoleID
    WHERE gd.ID IN (
        SELECT DISTINCT Achievements.GameID
        FROM Awarded
        INNER JOIN Achievements ON Awarded.AchievementID = Achievements.ID
        WHERE Awarded.User = '$user' AND Achievements.Flags = 3
    )
    ORDER BY gd.Title";

    $lightResults = legacyDbFetchAll($lightQuery)->toArray();

    // Merge cached award data
    foreach ($lightResults as &$game) {
        $gameId = $game['GameID'];

        $game['MaxPossible'] ??= 0;
        $game['NumAwarded'] ??= 0;
        $game['NumAwardedHC'] ??= 0;
        $game['PctWon'] ??= 0;
        $game['PctWonHC'] ??= 0;

        if (isset($awardedCache[$gameId])) {
            $numAwarded = (int) $awardedCache[$gameId]['NumAwarded'];
            $numAwardedHC = (int) $awardedCache[$gameId]['NumAwardedHC'];
            $maxPossible = (int) $awardedCache[$gameId]['MaxPossible'];
            $mostRecentWonDate = $awardedCache[$gameId]['MostRecentWonDate'];
            $firstWonDate = $awardedCache[$gameId]['FirstWonDate'];

            $game['MaxPossible'] = $maxPossible;
            $game['NumAwarded'] = $numAwarded;
            $game['NumAwardedHC'] = $numAwardedHC;
            $game['PctWon'] = $maxPossible ? $numAwarded / $maxPossible : 0;
            $game['PctWonHC'] = $maxPossible ? $numAwardedHC / $maxPossible : 0;
            $game['MostRecentWonDate'] = $mostRecentWonDate;
            $game['FirstWonDate'] = $firstWonDate;
        }
    }

    // Make sure we're sorting correctly similar to the costly query in getUsersCompletedGamesAndMax().
    usort($lightResults, function ($a, $b) {
        // Check if either game has 100% achievements won.
        $a100Pct = (isset($a['PctWon']) && $a['PctWon'] == 1.0);
        $b100Pct = (isset($b['PctWon']) && $b['PctWon'] == 1.0);

        // If one game has 100% and the other doesn't, sort accordingly.
        if ($a100Pct && !$b100Pct) {
            return -1;
        }
        if (!$a100Pct && $b100Pct) {
            return 1;
        }

        if ($a['PctWon'] != $b['PctWon']) {
            return $b['PctWon'] <=> $a['PctWon']; // Sort by PctWon descending
        }
        if ($a['PctWonHC'] != $b['PctWonHC']) {
            return $b['PctWonHC'] <=> $a['PctWonHC']; // Sort by PctWonHC descending
        }
        if ($a['MaxPossible'] != $b['MaxPossible']) {
            return $b['MaxPossible'] <=> $a['MaxPossible']; // Sort by MaxPossible descending
        }

        return $a['Title'] <=> $b['Title']; // Sort by Title ascending
    });

    // Return combined results
    return $lightResults;
}

/**
 * @deprecated TODO Remove when denormalized data is ready. See comments in getUsersCompletedGamesAndMax().
 */
function prepareUserCompletedGamesCacheValue(array $allFetchedResults): string
{
    // Extract awarded data
    $awardedCacheString = '';
    foreach ($allFetchedResults as $result) {
        $gameId = $result['GameID'];
        $maxPossible = $result['MaxPossible'];
        $numAwarded = $result['NumAwarded'];
        $numAwardedHC = $result['NumAwardedHC'];
        $mostRecentWonDate = $result['MostRecentWonDate'];
        $firstWonDate = $result['FirstWonDate'];

        $awardedCacheString .= "$gameId|$maxPossible|$numAwarded|$numAwardedHC|$mostRecentWonDate|$firstWonDate,";
    }

    // Remove last comma
    $awardedCacheString = rtrim($awardedCacheString, ',');

    return $awardedCacheString;
}

function getUsersCompletedGamesAndMax(string $user): array
{
    if (!isValidUsername($user)) {
        return [];
    }

    $minAchievementsForCompletion = 5;

    $query = "SELECT gd.ID AS GameID, c.Name AS ConsoleName, c.ID AS ConsoleID,
            gd.ImageIcon, gd.Title, gd.achievements_published as MaxPossible,
            pg.first_unlock_at AS FirstWonDate, pg.last_unlock_at AS MostRecentWonDate,
            pg.achievements_unlocked AS NumAwarded, pg.achievements_unlocked_hardcore AS NumAwardedHC, " .
            floatDivisionStatement('pg.achievements_unlocked', 'gd.achievements_published') . " AS PctWon, " .
            floatDivisionStatement('pg.achievements_unlocked_hardcore', 'gd.achievements_published') . " AS PctWonHC
        FROM player_games AS pg
        LEFT JOIN GameData AS gd ON gd.ID = pg.game_id
        LEFT JOIN Console AS c ON c.ID = gd.ConsoleID
        LEFT JOIN UserAccounts ua ON ua.ID = pg.user_id
        WHERE ua.User = :user
        AND gd.achievements_published > $minAchievementsForCompletion
        ORDER BY PctWon DESC, PctWonHC DESC, MaxPossible DESC, gd.Title";

    return legacyDbFetchAll($query, ['user' => $user])->toArray();
}

function getGameRecentPlayers(int $gameID, int $maximum_results = 10): array
{
    $retval = [];

    $sessions = PlayerSession::where('game_id', $gameID)
        ->join('UserAccounts', 'UserAccounts.ID', '=', 'user_id')
        ->where('UserAccounts.Permissions', '>=', Permissions::Unregistered)
        ->whereNotNull('rich_presence')
        ->orderBy('rich_presence_updated_at', 'DESC')
        ->groupBy('user_id')
        ->select(['user_id', 'User', 'rich_presence', DB::raw('MAX(rich_presence_updated_at) AS rich_presence_updated_at')]);

    if ($maximum_results) {
        $sessions = $sessions->limit($maximum_results);
    }

    foreach ($sessions->get() as $session) {
        $retval[] = [
            'UserID' => $session->user_id,
            'User' => $session->User,
            'Date' => $session->rich_presence_updated_at->__toString(),
            'Activity' => $session->rich_presence,
        ];
    }

    if ($maximum_results) {
        $maximum_results -= count($retval);
        if ($maximum_results == 0) {
            return $retval;
        }
    }

    $userFilter = '';
    if (count($retval)) {
        $userFilter = 'AND ua.ID NOT IN (' . implode(',', array_column($retval, 'UserID')) . ')';
    }

    $query = "SELECT ua.ID as UserID, ua.User, ua.RichPresenceMsgDate AS Date, ua.RichPresenceMsg AS Activity
              FROM UserAccounts AS ua
              WHERE ua.LastGameID = $gameID AND ua.Permissions >= " . Permissions::Unregistered . "
              AND ua.RichPresenceMsgDate > TIMESTAMPADD(MONTH, -6, NOW()) $userFilter
              ORDER BY ua.RichPresenceMsgDate DESC";

    if ($maximum_results > 0) {
        $query .= " LIMIT $maximum_results";
    }

    foreach (legacyDbFetchAll($query) as $data) {
        $retval[] = $data;
    }

    return $retval;
}

/**
 * @deprecated use denormalized data from player_games
 */
function expireGameTopAchievers(int $gameID): void
{
    $cacheKey = "game:$gameID:topachievers";
    Cache::forget($cacheKey);
}

/**
 * Gets a game's high scorers or latest masters.
 *
 * @deprecated use denormalized data from player_games
 */
function getGameTopAchievers(int $gameID): array
{
    $cacheKey = "game:$gameID:topachievers";
    $retval = Cache::get($cacheKey);
    if ($retval !== null) {
        return $retval;
    }

    $high_scores = [];
    $masters = [];
    $numAchievementsInSet = 0;

    $query = "SELECT COUNT(*) AS NumAchievementsInSet
        FROM Achievements
        WHERE GameID = $gameID AND Flags = " . AchievementFlag::OfficialCore;
    $data = legacyDbFetch($query);
    if ($data !== null) {
        $numAchievementsInSet = $data['NumAchievementsInSet'];
    }

    $query = "SELECT ua.User, pg.achievements_unlocked_hardcore AS NumAchievements,
                        pg.points_hardcore AS TotalScore, pg.last_unlock_hardcore_at AS LastAward
                FROM player_games pg
                INNER JOIN UserAccounts ua ON ua.ID = pg.user_id
                WHERE ua.Untracked = 0
                AND pg.game_id = $gameID
                AND pg.achievements_unlocked_hardcore > 0
                ORDER BY TotalScore DESC, NumAchievements DESC, LastAward";

    $mastersCounter = 0;
    foreach (legacyDbFetchAll($query) as $data) {
        settype($data['NumAchievements'], 'integer');
        settype($data['TotalScore'], 'integer');

        if (count($high_scores) < 10) {
            $high_scores[] = $data;
        }

        if ($data['NumAchievements'] == $numAchievementsInSet) {
            if (count($masters) == 10) {
                array_shift($masters);
            }
            $data['Rank'] = ++$mastersCounter;
            $masters[] = $data;
        } elseif (count($high_scores) == 10) {
            break;
        }
    }

    $retval = [];
    $retval['Masters'] = array_reverse($masters);
    $retval['HighScores'] = $high_scores;

    if (count($masters) == 10) {
        // only cache the result if the masters list is full.
        // that way we only have to expire it when there's a new mastery
        // or an achievement gets promoted or demoted
        Cache::put($cacheKey, $retval, Carbon::now()->addDays(30));
    }

    return $retval;
}
