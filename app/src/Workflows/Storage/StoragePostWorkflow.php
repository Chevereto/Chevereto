<?php

/*
 * This file is part of Chevereto.
 *
 * (c) Rodolfo Berrios <rodolfo@chevereto.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Chevereto\Workflows\Storage;

use Chevere\Workflow\Interfaces\WorkflowInterface;
use Chevereto\Workflow\BaseWorkflow;
use function Chevere\Workflow\job;
use function Chevere\Workflow\workflow;

final class StoragePostWorkflow extends BaseWorkflow
{
    public function getWorkflow(): WorkflowInterface
    {
        return workflow(
            checkAdmin: job(
                'CheckAdminAction',
                parameter: '${variable}',
            ),
            insert: job(
                'StorageInsertAction',
                account_id: '${account_id}',
                account_name: '${account_name}',
                apiId: '${api_id}',
                bucket: '${bucket}',
                capacity: '${capacity}',
                id: '${id}',
                key: '${key}',
                name: '${name}',
                region: '${region}',
                secret: '${secret}',
                server: '${server}',
                service: '${service}',
                url: '${url}',
            ),
        );
    }
}
