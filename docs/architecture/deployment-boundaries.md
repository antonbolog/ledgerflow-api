# Deployment Boundaries

Ledgerflow separates application delivery from infrastructure ownership across three repositories.
The repositories coordinate through immutable artifacts and explicit runtime contracts rather
than one central repository building every application.

## Repository ownership

| Repository | Builds | Owns |
|---|---|---|
| `ledgerflow-api` | Laravel application image | Backend source, Dockerfile, tests, CI, runtime commands, health and configuration contracts |
| `ledgerflow-spa` | Next.js/React application image or static artifact | Frontend source, tests, CI, rendering and client runtime contracts |
| `ledgerflow-infra` | Terraform plans and deployment changes | AWS resources, environments, IAM, networking, ECS services, scaling, alarms, and artifact deployment |

Application repositories publish immutable artifacts tagged by commit SHA and, where supported,
record the image digest. `ledgerflow-infra` consumes those artifacts; it does not clone and build
application source as part of an infrastructure deployment.

## Initial delivery model

```text
application commit
  -> repository-defined validation
  -> build application image
  -> push immutable image to ECR
  -> authenticate to AWS through GitHub Actions OIDC
  -> deploy the corresponding ECS service using infra-owned configuration
```

The first implementation may let each application workflow trigger its bounded ECS deployment.
Terraform remains the authority for infrastructure shape. A later GitOps-style flow may update an
image digest through a reviewed `ledgerflow-infra` change when release coordination or audit needs
justify the extra cross-repository step.

## Planned AWS topology

`ledgerflow-infra` is expected to own:

- ECR repositories for API and SPA artifacts;
- ECS Fargate services and task definitions;
- an Application Load Balancer and routing for API, admin, SPA, and Reverb where applicable;
- RDS PostgreSQL;
- ElastiCache Redis, including the BFF session store under the durability, isolation, and
  `noeviction` contract in
  [merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md#redis-availability-and-durability);
- SQS queues and DLQs for selected workloads;
- CloudWatch logs, metrics, alarms, and dashboards;
- IAM roles and GitHub Actions OIDC trust;
- DNS, TLS, networking, runtime configuration, and secret delivery;
- optional Amazon OpenSearch Service after its adoption gate passes.

The API image may run multiple independently supervised processes: HTTP/Filament, Horizon,
scheduler/relay, Reverb, and future SQS workers. Reusing an image does not merge their scaling,
health, or restart ownership.

## Frontend deployment gate

`ledgerflow-spa` is intended to use Next.js/React, but the repository name does not force a pure
client-only rendering model. Before implementation, choose between SSR/runtime hosting and a
static deployment based on authentication, realtime behavior, caching, and product needs.

- An SSR/runtime build can use ECR and ECS Fargate behind the shared edge topology.
- A static build can use S3 and CloudFront.

The selected model belongs in the SPA repository and `ledgerflow-infra`, not in backend code.

## Delivery safety

- GitHub Actions assumes bounded AWS roles through OIDC; no long-lived AWS keys are committed or
  stored as ordinary repository secrets.
- Production resource changes originate from `ledgerflow-infra`.
- Application workflows may update only their approved deployable service and artifact version.
- Database migrations, rollback behavior, health gates, and deployment interruption handling must
  be specified before automatic production delivery.
- Argo CD is not part of the ECS Fargate design; it would become relevant only after an explicit
  move to Kubernetes/EKS.

## OpenSearch adoption gate

Amazon OpenSearch Service is not provisioned for portfolio completeness. Adopt it only after:

1. a concrete search or reporting query cannot be served acceptably by PostgreSQL/read models;
2. indexing ownership, replay, consistency, and failure recovery are specified;
3. expected data volume and query load justify the operating cost;
4. security, backup, observability, and deletion requirements are understood.

Until then, PostgreSQL projections remain the default search/reporting source.

