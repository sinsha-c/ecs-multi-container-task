# ECS Multi-Container Deployment with Secrets Manager & IAM Task Roles

A hands-on AWS ECS (Fargate) project demonstrating a multi-container task, environment variable configuration, secure secrets injection via AWS Secrets Manager, and load-balanced service deployment.

![Status](https://img.shields.io/badge/status-in--progress-yellow) ![AWS](https://img.shields.io/badge/AWS-ECS%20Fargate-orange) ![Docker](https://img.shields.io/badge/Docker-PHP%2FApache-blue)

---

## Architecture

![ECS multi-container architecture diagram](screenshots/ecs_multi_container_architecture.png)

Traffic flows from the internet through the ALB and target group to two ECS Fargate tasks. Each task runs two containers — the main `apache` container and a `helper` (BusyBox) sidecar. Both tasks retrieve `DB_PASSWORD` from AWS Secrets Manager at startup via the Task Execution Role.

---

## Project Overview

This project deploys a simple PHP web application to **Amazon ECS (Fargate)** behind an **Application Load Balancer**, with two containers running side-by-side in a single ECS Task:

- **`apache`** — serves the PHP web app
- **`helper`** — a lightweight BusyBox **sidecar container** simulating a background/log-processing process

The app displays its configuration at runtime to prove that:
- Config values come from **environment variables** (not hardcoded)
- Sensitive values (like the DB password) come from **AWS Secrets Manager** (not hardcoded, not baked into the image)
- ECS is auto-healing (killing a task causes ECS to replace it automatically)
- The ALB load-balances across multiple running tasks (hostname changes on refresh)

### Why this project matters (portfolio angle)
This lab demonstrates practical, production-relevant ECS skills:
- Designing multi-container task definitions (sidecar pattern)
- Securing secrets using IAM roles instead of hardcoded credentials
- Understanding the difference between **Task Role** vs **Execution Role**
- Debugging real IAM permission errors on secret retrieval
- Deploying a containerized app behind a Load Balancer with health checks

---

## Key Concepts Covered

| Concept | What it means in this project |
|---|---|
| **Task Role** | Grants permissions to the *application inside the container* (e.g., reading from S3, DynamoDB) |
| **Execution Role** | Grants permissions to *ECS itself* to pull the image, retrieve secrets, and write logs |
| **Sidecar container** | A helper container (`helper`) running alongside the main app container in the same Task |
| **Environment variables** | Non-sensitive config (e.g., `APP_NAME`) injected at runtime, no rebuild needed |
| **Secrets Manager** | Sensitive config (e.g., `DB_PASSWORD`) securely stored, encrypted, and injected at task startup |
| **Task Definition versions** | Every change (e.g., new image tag) creates a new revision; old revisions remain for rollback |
| **Self-healing** | Stopping a task manually causes ECS to automatically launch a replacement |

---

## Prerequisites

- AWS account with permissions for ECS, ECR, IAM, Secrets Manager, and ELB
- AWS CLI configured (`aws configure`)
- Docker installed locally
- Basic familiarity with PHP and Docker

---

## Project Structure

```
ecs-demo/
├── Dockerfile
├── index.php
└── README.md
```

---

## Step-by-Step Build Guide

### Step 1 — Create the project folder
```bash
mkdir ecs-demo && cd ecs-demo
```

### Step 2 — Write the PHP application
`index.php` reads configuration from environment variables — nothing is hardcoded.

```php
<?php
echo "<h1>Amazon ECS Demo</h1>";
echo "<hr>";
echo "<h2>Application Name</h2>";
echo getenv("APP_NAME");
echo "<br><br>";
echo "<h2>Database Password</h2>";
echo getenv("DB_PASSWORD");
echo "<br><br>";
echo "<h2>Hostname</h2>";
echo gethostname();
?>
```

### Step 3 — Write the Dockerfile
```dockerfile
FROM php:8.2-apache
COPY index.php /var/www/html/
EXPOSE 80
```

### Step 4 — Build the image
```bash
docker build -t ecs-demo .
docker images   # verify ecs-demo:latest exists
```

### Step 5 — Test locally
```bash
docker run -d -p 8080:80 \
  -e APP_NAME="Training Portal" \
  -e DB_PASSWORD="LocalPassword" \
  ecs-demo
```
Visit `http://localhost:8080` and confirm the app displays the app name, password, and container hostname.

> local browser output showing app name, password, and hostname
> <img src="screenshots/dashboard.png" alt="local test screenshot" width="700">

### Step 6 — Create an ECR repository
AWS Console → **ECR** → Create Repository → name it `ecs-demo` (private).

> repository created, empty (before push)
> <img src="screenshots/02-ecr-repo.png" alt="ecr-repo screenshot" width="700">

### Step 7 — Push the image to ECR
```bash
aws ecr get-login-password --region <your-region> | \
  docker login --username AWS --password-stdin <account-id>.dkr.ecr.<region>.amazonaws.com

docker tag ecs-demo:latest <account-id>.dkr.ecr.<region>.amazonaws.com/ecs-demo:latest
docker push <account-id>.dkr.ecr.<region>.amazonaws.com/ecs-demo:latest
```

> pushed image visible in the ECR repository
> <img src="screenshots/02-ecr-repo-after-push.png" alt="ecr-repo screenshot" width="700">

### Step 8 — Store the secret in Secrets Manager
AWS Console → **Secrets Manager** → Store a new secret → *Other type of secret*
- Key: `DB_PASSWORD`
- Value: `MyPassword@123`
- Secret name: `ecs-db-secret`

> secret created in Secrets Manager
> <img src="screenshots/03-secrets-manager.png" alt="secrets-manager screenshot" width="700">

### Step 9 — Create the ECS cluster
AWS Console → **ECS** → Clusters → Create Cluster
- Name: `training-cluster`
- Infrastructure: **AWS Fargate**

> Cluster overview
> <img src="screenshots/04-ecs-cluster.png" alt="secrets-manager screenshot" width="700">

### Step 10 — Create the Task Definition (multi-container)

**Task-level configuration**
| Field | Value |
|---|---|
| Task definition family | `ecs-demo-task` |
| Launch type | `AWS Fargate` |
| Operating system/architecture | `Linux/X86_64` |
| Network mode | `awsvpc` (required for Fargate) |
| Task CPU | `0.5 vCPU` |
| Task memory | `1 GB` |
| Task role | *(none required for this demo — no in-app AWS API calls)* |
| Task execution role | `ecsTaskExecutionRole` |
 
**Container 1 — `apache`**
| Field | Value |
|---|---|
| Image | `<ECR image URI>` |
| Port | 80 |
| Environment variable | `APP_NAME = Training Portal` |
| Secret | `DB_PASSWORD → ecs-db-secret` |
 
**Container 2 — `helper` (sidecar)**
 
In the console, click **Add container** a second time inside the same task definition, then fill in:
 
| Field | Value | Notes |
|---|---|---|
| Container name | `helper` | |
| Image URI | `busybox` | Pulled directly from Docker Hub — no ECR push needed for this one |
| Essential container | **Off / unchecked** | Recommended for a sidecar — if `helper` crashes, the task keeps running instead of taking `apache` down with it |
| Port mappings | *(leave empty)* | The helper doesn't listen on any port |
| Environment variables | *(none)* | Not needed for this container |
| Command override | `sh,-c,while true; do echo "Helper Running"; sleep 20; done` | Console command fields are comma-separated — this reproduces `sh -c "..."` as three separate arguments |
| CPU / Memory (soft/hard limits) | *(leave empty)* | Falls back to sharing the task-level 0.5 vCPU / 1 GB pool |
| Log collection | Enable (defaults to `awslogs` → CloudWatch) | Lets you see "Helper Running" appear every 20 seconds in CloudWatch Logs — useful for confirming it's alive |
 
> 💡 **Where to find the Command field, and how to enter it:**
> 1. Inside the `helper` container's settings, scroll down to the **Environment variables** section (same section where you'd add key/value pairs for this container — but leave the table empty here).
> 2. Below the environment variables table, you'll find two separate text boxes: **Entry point** and **Command**.
> 3. Leave **Entry point** blank.
> 4. In **Command**, type: `sh,-c,while true; do echo "Helper Running"; sleep 20; done`
>
> The console treats **every comma as a split point between array items** — so this becomes exactly 3 arguments: `sh`, `-c`, and `while true; do echo "Helper Running"; sleep 20; done` (the semicolons inside that third piece are just part of the shell script text, not additional splits). Don't add spaces after the commas or extra quotes around the whole thing — type it exactly as shown.
 
> 💡 **Command vs Entrypoint:** leave "Entrypoint" empty here and only set "Command" — this overrides the default `CMD` of the `busybox` image without touching its `ENTRYPOINT`. If you needed to override both, you'd fill in Entrypoint too, but this image doesn't require it.
 
> 💡 Task CPU/memory (0.5 vCPU / 1 GB) is split across both containers combined — Fargate doesn't let you assign CPU/memory per-container unless you explicitly set limits on each. For this demo, leaving per-container limits unset lets both containers share the task-level pool.

> Task definition showing both containers configured
> <img src="screenshots/05-task-definition-c1.png" alt="container1 screenshot" width="700">
> <img src="screenshots/05-task-definition-c2.png" alt="container2 screenshot" width="700">

### Step 11 — Create an Application Load Balancer
- Internet-facing
- Listener: HTTP:80
- At least 2 public subnets
- Security group: allow HTTP 80 from anywhere

### Step 12 — Create a Target Group
- Target type: **IP**
- Protocol: HTTP, Port 80
- Health check path: `/`

> will update`screenshots/06-alb-target-group.png` — ALB listener and target group configuration

### Step 13 — Create the ECS Service
- Cluster: `training-cluster`
- Task Definition: `ecs-demo-task`
- Desired count: `2`
- Networking: same VPC, public subnets, auto-assign public IP (lab setting only)
- Load balancer: attach the ALB, listener, and target group from Steps 11–12

### Step 14 — Wait for deployment
Confirm both tasks (each running `apache` + `helper`) reach the **Running** state.

> will update`screenshots/07-service-running-tasks.png` — ECS service showing 2/2 tasks running

### Step 15 — Verify via the ALB
Open the ALB's DNS name in a browser. Refresh repeatedly — the **Hostname** value should change as the ALB load-balances between tasks.

> will update `screenshots/08-alb-output.png` — app output via ALB DNS (capture two refreshes to show the hostname change)

---

## Troubleshooting: Fixing Secrets Manager Access

If the container fails to start or can't retrieve `DB_PASSWORD`, the **Execution Role** likely lacks permission.

1. IAM → Roles → search `ecsTaskExecutionRole`
2. Confirm it only has `AmazonECSTaskExecutionRolePolicy` (does **not** grant secret access by default)
3. Add an inline policy:
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": "arn:aws:secretsmanager:<region>:<account-id>:secret:ecs-db-secret-*"
    }
  ]
}
```
> Use a trailing `-*` since AWS appends a random suffix to secret ARNs.

4. Name the policy (e.g., `SecretsManagerAccess`) and create it
5. Go to ECS → Cluster → Service → **Update Service** → **Force new deployment** (no other changes needed)

> 📸 `screenshots/09-iam-policy-fix.png` — inline policy attached to `ecsTaskExecutionRole`

---

## Validation Checklist

- [ ] Local Docker container runs and displays env vars correctly
- [ ] Image pushed to ECR successfully
- [ ] Secret created in Secrets Manager
- [ ] ECS cluster, task definition (2 containers), and service created
- [ ] ALB routes traffic and health checks pass
- [ ] Refreshing the page shows different hostnames (load balancing confirmed)
- [ ] Manually stopping a task triggers automatic replacement (self-healing confirmed)
- [ ] Execution Role has explicit `secretsmanager:GetSecretValue` permission

---

## Cleanup (avoid ongoing charges)
- Delete the ECS Service → Cluster
- Deregister old Task Definitions (optional)
- Delete the ALB and Target Group
- Delete the ECR repository/image
- Delete the secret in Secrets Manager
- Delete the inline IAM policy if no longer needed

---

## What I Learned
- The difference between **Task Role** and **Execution Role** and when each applies
- Why hardcoding secrets (in code, Dockerfile, or plain task definition env vars) is insecure
- How to implement the **sidecar container pattern** for auxiliary/background processes
- How ECS Fargate + ALB provide automatic scaling, healing, and traffic distribution
- How to debug real-world IAM permission issues tied to Secrets Manager

---

## Tags
`aws` `ecs` `fargate` `docker` `iam` `secrets-manager` `devops` `alb` `sidecar-pattern`
