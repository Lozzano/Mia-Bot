<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Game;
use App\Models\Roll;
use App\Models\User;
use App\Jobs\ProcessMessage;
use App\Models\GameParticipant;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\BotMan;
use Log;

class MiaBotController extends Controller
{

    protected $user;
    protected $game;
    protected $calls;
    protected $first_round = false;
    protected $participants;
    protected $current_call;
    protected $dice_left_in_game;
    protected $participant_count;
    protected $current_round_rolls;
    protected $current_participant;
    protected $current_round_participants;
    protected $current_round_participant_count;
    protected $current_eligible_participant_order;
    protected $next_eligible_participant_order;
    protected $next_participant;
    protected $next_user;
    protected $end_round_hits;
    protected $end_round_dice_face_to_look_for;

    protected $emoji_numbers = [
        1 => ":one:",
        2 => ":two:",
        3 => ":three:",
        4 => ":four:",
        5 => ":five:",
        6 => ":six:",
        7 => ":seven:",
        8 => ":eight:",
        9 => ":nine:",
        10 => ":keycap_ten:",
    ];

    public function handle(BotMan $bot)
    {
        ProcessMessage::dispatch($bot);
        return response()->json([
            'status' => 'success'
        ], 200);
    }

    public function help(BotMan $bot)
    {
        $this->handleUser($bot);
        if(!$this->messageSentToBot($bot)) {
            return;
        }
        $message = OutgoingMessage::create('The rules for the games are a bit too long to post on slack.. so here is a link: https://mia.valentin.nu/help');
        $bot->say($message, $this->user->slack_id);
    }

    public function host(BotMan $bot)
    {
        $this->handleUser($bot);
        if($this->messageSentToBot($bot)) {
            return;
        }

        $open_game = Game::where('state', 'open')
            ->where('slack_team_id', $this->user->slack_team_id)
            ->first();
        if(!empty($open_game)) {
            $date = new \DateTime;
            $date->modify('-10 minutes');
            $formatted_date = $date->format('Y-m-d H:i:s');

            if($open_game->created_at < $formatted_date) {
                $open_game->state = 'timed_out';
                $open_game->save();
            }else{
                $open_host = User::find($open_game->host_id);
                $bot->reply("Another open game is currently recruiting players, <@" . $open_host->slack_id . "> is hosting.. It has to start before you can start another! Type \"me\" to join that game!");
                return;
            }
        }

        // Setting game defaults
        $life_count = 6;

        // Take bot message and get rid of the "host liar" part, only keeping any arguments it might contain.
        $message = substr($bot->getMessage()->getText(), 9);
        // Check if there could even possibly be any arguments after the "host liar" message.
        preg_match_all("/--\w+=\w+/", $message, $matches);
        if(count($matches)) {
            // Here we want to look for any arguments in the following format "--key=value"
            foreach($matches[0] as $arg){
                // Checking if we should change the default life from 6 to another value this game.
                if(substr($arg, 0, 7) == "--life=") {
                    if(is_numeric(substr($arg, 7)) && substr($arg, 7) <= 10) {
                        $life_count = substr($arg, 7);
                    }
                }
            }
        }

        $this->game = new Game;
        $this->game->slack_team_id = $this->user->slack_team_id;
        $this->game->host_id = $this->user->id;
        $this->game->state = 'open';
        $this->game->life = $life_count;
        $this->game->save();

        $this->current_participant = new GameParticipant;
        $this->current_participant->game_id = $this->game->id;
        $this->current_participant->participant_id = $this->user->id;
        $this->current_participant->participant_life = $life_count;
        $this->current_participant->save();

        Log::info("[INFO] New game of Mia starting! ID: " . $this->game->id . " Host: " . $this->user->username);

        $bot->reply("Let's play *Mia*! <@" . $this->user->slack_id . "> is hosting.. Type \"me\" to join!");
        return;
    }

    public function join(BotMan $bot)
    {
        $this->handleUser($bot);

        // Getting the currently open game
        $this->game = Game::where('state', 'open')
            ->where('slack_team_id', $this->user->slack_team_id)
            ->first();
        if(empty($this->game)) {
            $bot->reply("There doesn't seem to be any open games right now.. :thinking_face: You could host one by typing 'play mia'?");
            return;
        }

        if($this->game->host_id === $this->user->id) {
            $bot->reply("You are trying to join your own game.. FeelsBadMan..");
            return;
        }

        // At this point we KNOW that the user who is trying to join CAN join
        $current_participant_check = GameParticipant::where('game_id', $this->game->id)
            ->where('participant_id', $this->user->id)
            ->first();
        if(!empty($current_participant_check)) {
            $bot->reply("You are already in the game!");
            return;
        }

        $participant = new GameParticipant;
        $participant->game_id = $this->game->id;
        $participant->participant_id = $this->user->id;
        $participant->participant_life = $this->game->life;
        $participant->save();

        $bot->reply("<@".$this->user->slack_id."> successfully joined the game.. please wait for the host to start it!");
    }

    public function leave(BotMan $bot)
    {
        $this->handleUser($bot);

        // Getting the currently open game
        $this->game = Game::where('state', 'open')
            ->where('slack_team_id', $this->user->slack_team_id)
            ->first();
        if(empty($this->game)) {
            $bot->reply("There doesn't seem to be any open games you can leave right now..");
            return;
        }

        if($this->game->host_id === $this->user->id) {
            $bot->reply("You can't leave your own game?! You can close it if you want to... :cry:");
            return;
        }

        $this->current_participant = GameParticipant::where('game_id', $this->game->id)
            ->where('participant_id', $this->user->id)
            ->first();
        if(empty($this->current_participant)) {
            $bot->reply("There doesn't seem to be any open games you can leave right now..");
            return;
        }

        GameParticipant::destroy($this->current_participant->id);

        $bot->reply("You left the game, great..");
    }

    // Method for the host of an game with an open game state, to start a game.
    public function start(BotMan $bot)
    {
        $this->handleUser($bot);
        if($this->messageSentToBot($bot)) {
            return;
        }

        // Getting the currently open game
        $this->game = Game::where('state', 'open')
            ->where('slack_team_id', $this->user->slack_team_id)
            ->first();
        if(empty($this->game)) {
            $bot->reply("There doesn't seem to be any games you can start right now.. :thinking_face: You could host one by asking if anyone wants to play?");
            return;
        }

        if($this->game->host_id !== $this->user->id) {
            $bot->reply("You are trying to start a game which you are not the host of!");
            return;
        }

        $participants = GameParticipant::where('game_id', $this->game->id)
            ->get();
        $participant_count = count($participants);
        if($participant_count < 2) {
            $bot->reply("You are currently the only participant of this game, a bit lonely, no? Please wait for others to join before starting the game..");
            return;
        }

        // Set order of players
        $first_player = null;
        $shuffled_participants = $participants->shuffle();
        foreach ($shuffled_participants AS $key => $participant) {
            $player = User::find($participant->participant_id);
            if ($key == 0) {
                $first_player = $player;
            }

            GameParticipant::where('game_id', $this->game->id)
                ->where('participant_id', $participant->participant_id)
                ->update([
                    'participant_order' => $key
                ]);

            // Notify players about game starting
            $bot->say("Alright, let's play Mia! There are *$participant_count* players in the game.. Everyone has *" . $this->game->life . "* life! Rolling the dice!", $player->slack_id);
            if ($key == 0) {
                $bot->say("You are the first player! You have the first call..", $player->slack_id);
            }else{
                $bot->say("<@" . $first_player->slack_id . "> is the first player!", $player->slack_id);
            }
        }

        $this->game->state = 'live';
        $this->game->save();

        $this->initTurn($bot, $shuffled_participants[0]);
    }

    public function playRound(BotMan $bot)
    {
        $this->handleUser($bot);
        if(!$this->messageSentToBot($bot)) {
            return;
        }

        $this->current_participant = GameParticipant::where('participant_id', $this->user->id)
            ->orderBy('created_at', 'desc')
            ->first();
        if(empty($this->current_participant)) {
            $bot->reply("Sorry, you don't seem to be in any live games.. Perhaps join or start one?");
            return;
        }

        $this->game = Game::find($this->current_participant->game_id);
        if(!isset($this->game) || $this->game->state != 'live') {
            $bot->reply("Sorry, you don't seem to be in any live games.. Perhaps join or start one?");
            return;
        }

        $this->participants = GameParticipant::where('game_id', $this->game->id)
            ->where('participant_life', '>', 0)
            ->orderBy('participant_order', 'asc')
            ->get();

        $this->current_round_participants = $this->participants;

        $this->participant_count = $this->participants->count();
        $this->current_round_participant_count = $this->current_round_participants->count();
        Log::info("[INFO] Current round participant count: " . $this->current_round_participant_count);

        $this->current_call = strtolower(trim($bot->getMessage()->getText()));
        Log::info("[INFO] User called: " . $this->current_call);

        $this->calls = Call::where('game_id', $this->game->id)
            ->where('call', '!=', 'shake')
            ->orderBy('created_at', 'desc')
            ->get();
        if($this->calls->isEmpty()) {
            $this->first_round = true;
            $this->current_eligible_participant_order = 0;
            $this->next_eligible_participant_order = 1;
        }else{
            // Find current & next eligible participant order
            $last_call = $this->calls->first();
            if($last_call->call == 'liar') {
                // Figure out who lost, that person becomes current eligible participant
                $this->current_eligible_participant_order = $this->current_round_participants
                    ->where('participant_id', $last_call->loser_id)
                    ->first()
                    ->participant_order;
                $current_eligible_participant_key = key($this->current_round_participants
                    ->where('participant_id', $last_call->loser_id)
                    ->all());
                if($current_eligible_participant_key == ($this->current_round_participant_count-1)) {
                    $this->next_eligible_participant_order = $this->current_round_participants
                        ->first()
                        ->participant_order;
                }else{
                    $this->next_eligible_participant_order = $this->current_round_participants[$current_eligible_participant_key+1]
                        ->participant_order;
                }
            }else{
                // Get the array position of the last player
                $last_participant_key = key($this->current_round_participants
                    ->where('participant_id', $last_call->participant_id)
                    ->all());
                if($last_participant_key == ($this->current_round_participant_count-1)) {
                    $this->current_eligible_participant_order = $this->current_round_participants
                        ->first()
                        ->participant_order;
                }else{
                    $this->current_eligible_participant_order = $this->current_round_participants[$last_participant_key+1]
                        ->participant_order;
                }
                $current_eligible_participant_key = key($this->current_round_participants
                    ->where('participant_order', $this->current_eligible_participant_order)
                    ->all());
                if($current_eligible_participant_key == ($this->current_round_participant_count-1)) {
                    $this->next_eligible_participant_order = $this->current_round_participants
                        ->first()
                        ->participant_order;
                }else{
                    $this->next_eligible_participant_order = $this->current_round_participants[$current_eligible_participant_key+1]
                        ->participant_order;
                }
            }
        }

        $this->next_participant = $this->current_round_participants->where('participant_order', $this->next_eligible_participant_order)->first();
        $this->next_user = User::find($this->next_participant->participant_id);

        if($this->current_participant->participant_order !== $this->current_eligible_participant_order) {
            $bot->reply("It's not your turn yet, please wait!");
            return;
        }

        if($this->current_call == 'liar') {
            $this->endRound($bot);
        }elseif($this->current_call == 'shake' && !$this->first_round) {
            $this->initTurn($bot, $this->current_participant);
        }else{
            $this->current_call = str_replace('.', ',', $this->current_call);
            if(!$this->isCallValid($this->current_call)) {
                $bot->reply("Your call wasn't valid, please try again..");
                return;
            }
            $this->continueRound($bot);
        }
    }

    public function continueRound(BotMan $bot)
    {
        if($this->first_round) {
            $current_call = new Call;
            $current_call->call = $this->current_call;
            $current_call->game_id = $this->game->id;
            $current_call->participant_id = $this->user->id;
            $current_call->participant_order = $this->current_participant->participant_order;
            $current_call->save();
        }else{
            $last_call_shake_check = Call::where('game_id', $this->game->id)
                ->orderBy('created_at', 'desc')
                ->first();
            if($last_call_shake_check->call != 'shake') {
                $bot->reply("You have to shake before calling something else.. Or call liar?!");
                return;
            }
            if($this->calls->first()->call != 'liar' && !$this->compareTwoCalls($this->current_call, $this->calls->first()->call)) {
                $bot->reply("You're call was lower than the person before you, please say something else..");
                return;
            }
            $current_call = new Call;
            $current_call->call = $this->current_call;
            $current_call->game_id = $this->game->id;
            $current_call->participant_id = $this->user->id;
            $current_call->participant_order = $this->current_participant->participant_order;
            $current_call->save();
        }

        foreach ($this->participants AS $participant) {
            $user = User::find($participant->participant_id);
            if($participant->participant_id == $this->next_participant->participant_id) {
                $bot->say("<@" . $this->user->slack_id . "> called $this->current_call", $user->slack_id);
                $bot->say("Now it's your turn! Say *shake* or *liar*!", $user->slack_id);
            }elseif($participant->participant_id == $this->current_participant->participant_id) {
                $bot->say("Now it's <@" . $this->next_user->slack_id . ">'s turn..", $user->slack_id);
            }else{
                $bot->say("<@" . $this->user->slack_id . "> called $this->current_call", $user->slack_id);
                $bot->say("Now it's <@" . $this->next_user->slack_id . ">'s turn..", $user->slack_id);
            }
        }

    }

    public function endRound(BotMan $bot)
    {
        $last_call = $this->calls->first();

        $exp_last_call = explode(",", $last_call->call);
        $dice_amount_to_look_for = $exp_last_call[0];
        $this->end_round_dice_face_to_look_for = $exp_last_call[1];

        $rolls = Roll::where('game_id', $this->game->id)
            ->orderBy('round', 'desc')
            ->get();
        $this->current_round_rolls = $rolls->where('round', $rolls->first()->round)->flatten();

        Log::info("[INFO] Dice amount to look for: $dice_amount_to_look_for");
        Log::info("[INFO] Dice face to look for: $this->end_round_dice_face_to_look_for");

        $this->end_round_hits = 0;
        foreach ($this->current_round_rolls AS $rolls) {

            if($this->game->staircase_enabled && $this->end_round_dice_face_to_look_for != 1) {
                // Checking for "Trappen" (ladder).
                $current_roll = json_decode($rolls->roll);
                $dice_count = count($current_roll);
                $ladder_counter = 1;
                sort($current_roll);
                foreach ($current_roll as $roll) {
                    if($ladder_counter == $roll) {
                        $ladder_counter++;
                    }
                }
                if($ladder_counter == ($dice_count+1)) {
                    $this->end_round_hits = $this->end_round_hits + $ladder_counter;
                    continue;
                }
            }

            foreach (json_decode($rolls->roll) as $roll) {
                if($roll == 1) {
                    $this->end_round_hits++;
                }elseif($roll == $this->end_round_dice_face_to_look_for) {
                    $this->end_round_hits++;
                }
            }
        }

        Log::info("[INFO] Hits: $this->end_round_hits");

        $loser_id = $last_call->participant_id;
        if($this->end_round_hits >= $dice_amount_to_look_for) {
            $loser_id = $this->user->id;
        }

        $current_call = new Call;
        $current_call->call = 'snyd';
        $current_call->game_id = $this->game->id;
        $current_call->participant_id = $this->user->id;
        $current_call->participant_order = $this->current_participant->participant_order;
        $current_call->loser_id = $loser_id;
        $current_call->save();

        $this->initRound($bot, $this->current_round_participants, null, $this->current_round_rolls->first()->round + 1, $loser_id);

        if($this->game->state == 'concluded') {
            return;
        }

        $this->next_participant = $this->current_round_participants->where('participant_id', $loser_id)->first();
        $this->next_user = User::find($loser_id);

        foreach ($this->current_round_participants as $participant) {
            $user = User::find($participant->participant_id);
            if($participant->participant_id == $this->next_participant->participant_id) {
                $bot->say("<@" . $this->user->slack_id . "> called liar and *" . ($loser_id == $this->user->id ? 'LOST' : 'WON') . "*! There were *$this->end_round_hits $this->end_round_dice_face_to_look_for's*.. There are *" . $this->dice_left_in_game . "* dice left..", $user->slack_id);
                $bot->say("Now it's your turn!", $user->slack_id);
            }elseif($participant->participant_id == $this->current_participant->participant_id) {
                $bot->say("You called liar and *" . ($loser_id == $this->user->id ? 'LOST' : 'WON') . "*! There were *$this->end_round_hits $this->end_round_dice_face_to_look_for's*.. There are *" . $this->dice_left_in_game . "* dice left..", $user->slack_id);
                $bot->say("Now it's <@" . $this->next_user->slack_id . ">'s turn..", $user->slack_id);
            }else{
                $bot->say("<@" . $this->user->slack_id . "> called liar and *" . ($loser_id == $this->user->id ? 'LOST' : 'WON') . "*! There were *$this->end_round_hits $this->end_round_dice_face_to_look_for's*.. There are *" . $this->dice_left_in_game . "* dice left..", $user->slack_id);
                $bot->say("Now it's <@" . $this->next_user->slack_id . ">'s turn..", $user->slack_id);
            }
        }
    }

    private function endGame(BotMan $bot, $looser_id)
    {
        $looser = User::find($looser_id);
        foreach ($this->participants as $participant) {
            $user = User::find($participant->participant_id);
            if($looser_id == $participant->participant_id) {
                $bot->say("You lost, there were *$this->end_round_hits $this->end_round_dice_face_to_look_for's*! Better luck next time..", $user->slack_id);
            }else{
                $bot->say("The game is over! <@" . $looser->slack_id . "> lost! Perhaps start another game?", $user->slack_id);
            }
        }
        // Setting the game state to be over.
        $this->game->state = 'concluded';
        $this->game->save();
    }

    public function abort(BotMan $bot)
    {
        $this->handleUser($bot);
        if(!$this->messageSentToBot($bot)) {
            return;
        }

        // Check if a game is LIVE where the current user is HOST
        $game_check = Game::where('state', 'live')
            ->where('host_id', $this->user->id)
            ->first();

        if(empty($game_check)) {
            $bot->reply("You are not currently hosting any open games, thus you cannot abort any! :thinking_face:");
        }else{
            $bot->reply(":scream: Okay, I'll abort that game for you..");
            $game_check->state = 'aborted';
            $game_check->save();

            $participants = GameParticipant::where('game_id', $game_check)->get();
            foreach ($participants AS $participant) {
                if($participant->participant_id == $this->user->id) {
                    continue;
                }
                $user = User::find($participant->participant_id);
                $bot->say("Your current game was aborted by the host! Please start a new game to continue playing..", $user->slack_id);
            }
        }
    }

    public function close(BotMan $bot)
    {
        $this->handleUser($bot);
        if($this->messageSentToBot($bot)) {
            return;
        }

        // Check if a game is OPEN where the current user is HOST
        $game_check = Game::where('state', 'open')
            ->where('host_id', $this->user->id)
            ->first();

        if(empty($game_check)) {
            $bot->reply("You are not currently hosting any open games, thus you cannot close any! :thinking_face:");
            return;
        }else{
            $bot->reply(":scream: Okay, I'll cancel that game for you..");
            $game_check->state = 'cancelled';
            $game_check->save();
        }
    }

    public function say(BotMan $bot)
    {
        $this->handleUser($bot);
        if(!$this->messageSentToBot($bot)) {
            return;
        }

        $games = Game::where('state', 'live')
            ->get();
        if(empty($games)) {
            $bot->reply("You are not currently participating in any games.");
            return;
        }

        $participating_in_game = false;
        foreach ($games AS $game) {
            $participant = GameParticipant::where('game_id', $game->id)
                ->where('participant_id', $this->user->id)
                ->first();
            if(!empty($participant)) {
                $this->game = $game;
                $this->current_participant = $participant;
                $participating_in_game = true;
                break;
            }
        }

        if(!$participating_in_game) {
            $bot->reply("You are not currently participating in any games.");
            return;
        }

        $message = substr($bot->getMessage()->getText(), 4);
        $this->participants = GameParticipant::where('game_id', $this->game->id)->get();
        foreach ($this->participants as $participant) {
            if($participant->participant_id != $this->user->id) {
                $user = User::find($participant->participant_id);
                $bot->say("<@" . $this->user->slack_id . ">: " . $message, $user->slack_id);
            }
        }
    }

    private function initTurn(BotMan $bot, $participant) {
        $player = User::find($participant->participant_id);

        if($this->current_call == 'shake') {
            $calls_incl_shake = Call::where('game_id', $this->game->id)
                ->orderBy('created_at', 'desc')
                ->get();
            if($calls_incl_shake->first()->participant_id == $participant->participant_id) {
                $bot->reply("No more shaking ;)");
                return;
            }
            $current_call = new Call;
            $current_call->call = $this->current_call;
            $current_call->game_id = $this->game->id;
            $current_call->participant_id = $this->user->id;
            $current_call->participant_order = $this->current_participant->participant_order;
            $current_call->save();
        }

        $dice = $this->rollDice();
        sort($dice);

        $roll = new Roll;
        $roll->roll = json_encode($dice);
        $roll->game_id = $this->game->id;
        $roll->participant_id = $participant->participant_id;
        $roll->save();

        $emoji_dice = "";
        foreach ($dice AS $die) {
            $emoji_dice .= $this->emoji_numbers[$die] . " ";
        }

        $bot->say("Your roll: " . $emoji_dice, $player->slack_id);
    }

    private function rollDice()
    {
        $no_of_dice = 2;
        $rolls = [];
        for ($c = 0; $c != $no_of_dice; $c++) {
            $roll = rand(1, 6);
            $rolls[] = $roll;
        }
        return $rolls;
    }

    private function isCallValid($call) {
        echo "Call: $call \n";
        if(str_contains($call, ',')) {
            $exp_call = explode(",", $call);
            if($exp_call[0] < $exp_call[1]) {
                return false;
            }
            if($exp_call[0] > 6 || $exp_call[1] > 6) {
                return false;
            }
            return true;
        }else{
            if($call == 'mia' || $call == 'small mia' || $call == 'liar') {
                return true;
            }
        }
        return false;
    }

    private function compareTwoCalls($current_call, $previous_call)
    {
        // Checking for "mia's"
        if($current_call == 'mia') {
            return true;
        }elseif($previous_call == 'small mia' && $current_call == 'small mia') {
            return true;
        }elseif($previous_call == 'mia' && $current_call == 'small mia') {
            return false;
        }elseif(($previous_call == 'small mia' || $previous_call == 'mia') && ($current_call != 'small mia' || $current_call != 'mia')) {
            return false;
        }elseif(($current_call == 'small mia' || $current_call == 'mia') && ($previous_call != 'small mia' || $previous_call != 'mia')) {
            return true;
        }

        if(str_contains($current_call, ',')) {
            $exp_current_call = explode(",", $current_call);
        }
        if(str_contains($previous_call, ',')) {
            $exp_previous_call = explode(",", $previous_call);
        }

        // Checking for pairs
        if($exp_previous_call[0] == $exp_previous_call[1] && $exp_current_call[0] != $exp_current_call[1]) {
            return false;
        }elseif($exp_previous_call[0] == $exp_previous_call[1] && $exp_current_call[0] == $exp_current_call[1]) {
            if($exp_previous_call[0] > $exp_current_call[0]) {
                return false;
            }else{
                return true;
            }
        }

        // Checking regular calls
        if($exp_current_call[0] > $exp_previous_call[0]) {
            return true;
        }elseif($exp_current_call[0] == $exp_previous_call[0]) {
            if($exp_current_call[1] >= $exp_previous_call[1]) {
                return true;
            }else{
                return false;
            }
        }else{
            return false;
        }
    }

    private function handleUser(BotMan $bot)
    {
        // Removing funky characters from user names.
        $username = preg_replace('/[^a-z\d \-\'\.]/i', '', $bot->getUser()->getUsername());

        if(!isset($bot->getUser()->getInfo()['team_id'])) {
            $team_id = request()->get('team_id');
        }else{
            $team_id = $bot->getUser()->getInfo()['team_id'];
        }

        Log::info("[INFO] User " . $username . " sent a message!");

        $this->user = User::updateOrCreate(
            [
                "slack_id"      => $bot->getUser()->getId()
            ],
            [
                "slack_team_id" => $team_id,
                "username"      => $username
            ]
        );
    }

    private function messageSentToBot(BotMan $bot)
    {
        $payload = $bot->getMessage()->getPayload();
        // D == Direct Message (with bot), G == Private Group/Channel, C == Public Channel
        if(substr($payload['channel'], 0 ,1) == 'D') {
            return true;
        }
        return false;
    }

}
