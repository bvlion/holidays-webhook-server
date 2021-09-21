<?php

namespace App\Http\Controllers\Api;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use App\Models\User;
use App\Models\Command;
use App\Models\SummarizeCommand;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommandExecController extends BaseApiController
{
  public function command(Request $request, int $id)
  {
    $command = Command::find($id);

    if ($command === null || $command->deleted_at !== null) {
      throw new HttpException(404, 'Don\'t found command');
    }

    $this->checkExecutableUser(
      $command->target_type,
      $command->target_id,
      $request->user(),
      'execute'
    );

    return $this->exec([$command]);
  }

  public function summary(Request $request, int $id)
  {
    $summarize_command = SummarizeCommand::find($id);

    if ($summarize_command === null || $summarize_command->deleted_at !== null) {
      throw new HttpException(404, 'Don\'t found summarize command');
    }

    $this->checkExecutableUser(
      $summarize_command->target_type,
      $summarize_command->target_id,
      $request->user(),
      'execute'
    );

    return $this->exec(
      Command::whereIn(
        'id', json_decode($summarize_command->commands, true)
      )->get()->all()
    );
  }

  private function exec(array $commands)
  {
    $results = [];

    foreach ($commands as $command) {
      $client = new Client();

      $options = [];
      $options['allow_redirects'] = true;

      $headers = json_decode($command->headers, true);
      if (!empty($headers)) {
        $options['headers'] = $headers;
      }

      if (!empty($command->parameters)) {
        $parameter = str_replace('##DATETIME##', date('Y-m-d H:i:s'), $command->parameters);
        if ($command->body_type == 'json') {
          $options[$command->body_type] = $parameter;
        } else {
          $options[$command->body_type] = json_decode($parameter, true);
        }
      }

      try {
        $res = $client->request($command->method, $command->url, $options);
        array_push($results, $this->saveResult($command->target_name, $res));
      } catch (RequestException $e) {
        array_push($results, $this->saveResult($command->target_name, $e->getResponse()));
      }
    }

    return $results;
  }

  private function saveResult(string $name, ResponseInterface $res) {
    return [
      'name' => $name,
      'response_code' => $res->getStatusCode(),
      'response_header' => json_encode($res->getHeaders(), true),
      'response_body' => $res->getBody(),
    ];
  }
}