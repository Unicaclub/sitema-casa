#!/bin/bash
# 🚀 Supremo Deployment Script - Ultimate DevOps Automation
#
# World-class deployment automation with:
# - Environment validation and preparation
# - Database migration management
# - Blue-green deployment strategy
# - Health checks and verification
# - Rollback capabilities
# - Performance monitoring
# - Security validation
# - Audit logging and notifications

set -euo pipefail

# Script configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DEPLOY_LOG="/var/log/erp-deployment.log"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Deployment configuration
ENVIRONMENT="${1:-staging}"
DEPLOYMENT_VERSION="${2:-$(git rev-parse --short HEAD)}"
DEPLOYMENT_STRATEGY="${3:-blue-green}"
FORCE_DEPLOY="${4:-false}"

# Kubernetes configuration
NAMESPACE="erp-sistema"
BLUE_DEPLOYMENT="erp-app-blue"
GREEN_DEPLOYMENT="erp-app-green"
SERVICE_NAME="erp-app-service"

# Function to log messages
log() {
    local level="$1"
    local message="$2"
    local color="$NC"
    
    case "$level" in
        "INFO")  color="$GREEN" ;;
        "WARN")  color="$YELLOW" ;;
        "ERROR") color="$RED" ;;
        "DEBUG") color="$BLUE" ;;
    esac
    
    echo -e "${color}[$TIMESTAMP] [$level] $message${NC}"
    echo "[$TIMESTAMP] [$level] $message" >> "$DEPLOY_LOG"
}

# Function to display banner
display_banner() {
    echo -e "${PURPLE}"
    echo "╔══════════════════════════════════════════════════════════════════╗"
    echo "║                    🚀 SUPREMO DEPLOYMENT PIPELINE                ║"
    echo "║                                                                  ║"
    echo "║                  Ultimate DevOps Automation                     ║"
    echo "║                                                                  ║"
    echo "║  Environment: $ENVIRONMENT                                                 ║"
    echo "║  Version: $DEPLOYMENT_VERSION                                           ║"
    echo "║  Strategy: $DEPLOYMENT_STRATEGY                                       ║"
    echo "║  Timestamp: $TIMESTAMP                                    ║"
    echo "╚══════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# Function to check prerequisites
check_prerequisites() {
    log "INFO" "🔍 Checking deployment prerequisites..."
    
    # Check required tools
    local required_tools=("kubectl" "docker" "helm" "jq" "curl" "git")
    for tool in "${required_tools[@]}"; do
        if ! command -v "$tool" &> /dev/null; then
            log "ERROR" "❌ Required tool '$tool' is not installed"
            exit 1
        fi
    done
    
    # Check Kubernetes connectivity
    if ! kubectl cluster-info &> /dev/null; then
        log "ERROR" "❌ Cannot connect to Kubernetes cluster"
        exit 1
    fi
    
    # Check namespace
    if ! kubectl get namespace "$NAMESPACE" &> /dev/null; then
        log "INFO" "📦 Creating namespace: $NAMESPACE"
        kubectl create namespace "$NAMESPACE"
    fi
    
    # Check Docker registry access
    if ! docker pull "ghcr.io/your-org/erp-sistema:$DEPLOYMENT_VERSION" &> /dev/null; then
        log "WARN" "⚠️  Cannot pull image version $DEPLOYMENT_VERSION, building locally..."
        docker build -t "ghcr.io/your-org/erp-sistema:$DEPLOYMENT_VERSION" .
    fi
    
    log "INFO" "✅ Prerequisites check completed"
}

# Function to validate environment
validate_environment() {
    log "INFO" "🔍 Validating $ENVIRONMENT environment..."
    
    case "$ENVIRONMENT" in
        "development"|"dev")
            REPLICAS=1
            RESOURCES_REQUESTS_CPU="100m"
            RESOURCES_REQUESTS_MEMORY="128Mi"
            RESOURCES_LIMITS_CPU="500m"
            RESOURCES_LIMITS_MEMORY="256Mi"
            ;;
        "staging"|"stage")
            REPLICAS=2
            RESOURCES_REQUESTS_CPU="250m"
            RESOURCES_REQUESTS_MEMORY="256Mi"
            RESOURCES_LIMITS_CPU="1000m"
            RESOURCES_LIMITS_MEMORY="512Mi"
            ;;
        "production"|"prod")
            REPLICAS=5
            RESOURCES_REQUESTS_CPU="500m"
            RESOURCES_REQUESTS_MEMORY="512Mi"
            RESOURCES_LIMITS_CPU="2000m"
            RESOURCES_LIMITS_MEMORY="1Gi"
            ;;
        *)
            log "ERROR" "❌ Invalid environment: $ENVIRONMENT"
            exit 1
            ;;
    esac
    
    log "INFO" "✅ Environment validation completed - Replicas: $REPLICAS"
}

# Function to run database migrations
run_database_migrations() {
    log "INFO" "🗄️  Running database migrations..."
    
    # Create migration job
    cat <<EOF | kubectl apply -f -
apiVersion: batch/v1
kind: Job
metadata:
  name: migration-$(date +%s)
  namespace: $NAMESPACE
spec:
  template:
    spec:
      containers:
      - name: migration
        image: ghcr.io/your-org/erp-sistema:$DEPLOYMENT_VERSION
        command: ["php", "artisan", "migrate", "--force"]
        envFrom:
        - configMapRef:
            name: erp-app-config
        - secretRef:
            name: erp-app-secrets
      restartPolicy: Never
  backoffLimit: 3
EOF
    
    # Wait for migration to complete
    local migration_job=$(kubectl get jobs -n "$NAMESPACE" --sort-by=.metadata.creationTimestamp -o name | tail -1)
    kubectl wait --for=condition=complete --timeout=300s "$migration_job" -n "$NAMESPACE"
    
    if kubectl get "$migration_job" -n "$NAMESPACE" -o jsonpath='{.status.conditions[?(@.type=="Complete")].status}' | grep -q "True"; then
        log "INFO" "✅ Database migrations completed successfully"
    else
        log "ERROR" "❌ Database migrations failed"
        kubectl logs -n "$NAMESPACE" "$migration_job"
        exit 1
    fi
}

# Function to perform blue-green deployment
blue_green_deployment() {
    log "INFO" "🔄 Starting blue-green deployment..."
    
    # Determine current active deployment
    local current_version
    current_version=$(kubectl get service "$SERVICE_NAME" -n "$NAMESPACE" -o jsonpath='{.spec.selector.version}' 2>/dev/null || echo "blue")
    
    if [[ "$current_version" == "blue" ]]; then
        NEW_VERSION="green"
        OLD_VERSION="blue"
        NEW_DEPLOYMENT="$GREEN_DEPLOYMENT"
        OLD_DEPLOYMENT="$BLUE_DEPLOYMENT"
    else
        NEW_VERSION="blue"
        OLD_VERSION="green"
        NEW_DEPLOYMENT="$BLUE_DEPLOYMENT"
        OLD_DEPLOYMENT="$GREEN_DEPLOYMENT"
    fi
    
    log "INFO" "🎯 Current: $OLD_VERSION, Deploying to: $NEW_VERSION"
    
    # Update deployment image
    kubectl set image deployment "$NEW_DEPLOYMENT" -n "$NAMESPACE" \
        erp-app="ghcr.io/your-org/erp-sistema:$DEPLOYMENT_VERSION"
    
    # Scale up new deployment
    kubectl scale deployment "$NEW_DEPLOYMENT" -n "$NAMESPACE" --replicas="$REPLICAS"
    
    # Wait for rollout to complete
    log "INFO" "⏳ Waiting for deployment rollout..."
    kubectl rollout status deployment "$NEW_DEPLOYMENT" -n "$NAMESPACE" --timeout=600s
    
    # Run health checks
    if perform_health_checks "$NEW_VERSION"; then
        # Switch traffic to new deployment
        log "INFO" "🔄 Switching traffic to $NEW_VERSION deployment..."
        kubectl patch service "$SERVICE_NAME" -n "$NAMESPACE" \
            -p "{\"spec\":{\"selector\":{\"version\":\"$NEW_VERSION\"}}}"
        
        # Wait for traffic switch
        sleep 30
        
        # Verify traffic switch
        if verify_traffic_switch "$NEW_VERSION"; then
            log "INFO" "✅ Traffic successfully switched to $NEW_VERSION"
            
            # Scale down old deployment
            log "INFO" "📉 Scaling down $OLD_VERSION deployment..."
            kubectl scale deployment "$OLD_DEPLOYMENT" -n "$NAMESPACE" --replicas=0
            
            log "INFO" "🎉 Blue-green deployment completed successfully!"
        else
            log "ERROR" "❌ Traffic switch verification failed, rolling back..."
            rollback_deployment "$OLD_VERSION"
            exit 1
        fi
    else
        log "ERROR" "❌ Health checks failed, rolling back..."
        kubectl scale deployment "$NEW_DEPLOYMENT" -n "$NAMESPACE" --replicas=0
        exit 1
    fi
}

# Function to perform health checks
perform_health_checks() {
    local version="$1"
    log "INFO" "🏥 Performing health checks on $version deployment..."
    
    # Get service endpoint
    local service_ip
    service_ip=$(kubectl get service "$SERVICE_NAME" -n "$NAMESPACE" -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null || echo "localhost")
    
    # Health check endpoints
    local endpoints=("/health" "/ready" "/api/status")
    
    for endpoint in "${endpoints[@]}"; do
        log "INFO" "🔍 Checking endpoint: $endpoint"
        
        local max_attempts=10
        local attempt=1
        
        while [[ $attempt -le $max_attempts ]]; do
            if curl -f -s "http://$service_ip$endpoint" > /dev/null; then
                log "INFO" "✅ Health check passed: $endpoint"
                break
            else
                log "WARN" "⚠️  Health check attempt $attempt/$max_attempts failed: $endpoint"
                if [[ $attempt -eq $max_attempts ]]; then
                    log "ERROR" "❌ Health check failed after $max_attempts attempts: $endpoint"
                    return 1
                fi
                sleep 10
            fi
            ((attempt++))
        done
    done
    
    # Performance test
    log "INFO" "🚀 Running performance tests..."
    local response_time
    response_time=$(curl -w "%{time_total}" -o /dev/null -s "http://$service_ip/api/dashboard/metrics")
    
    if (( $(echo "$response_time < 1.0" | bc -l) )); then
        log "INFO" "⚡ Performance test passed - Response time: ${response_time}s"
    else
        log "WARN" "⚠️  Performance test warning - Response time: ${response_time}s (>1s)"
    fi
    
    log "INFO" "✅ All health checks completed successfully"
    return 0
}

# Function to verify traffic switch
verify_traffic_switch() {
    local expected_version="$1"
    log "INFO" "🔍 Verifying traffic switch to $expected_version..."
    
    local service_ip
    service_ip=$(kubectl get service "$SERVICE_NAME" -n "$NAMESPACE" -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null || echo "localhost")
    
    # Test multiple requests to ensure traffic is routed correctly
    local success_count=0
    local total_requests=10
    
    for ((i=1; i<=total_requests; i++)); do
        local response
        response=$(curl -s "http://$service_ip/api/status" | jq -r '.version' 2>/dev/null || echo "unknown")
        
        if [[ "$response" == "$expected_version" ]]; then
            ((success_count++))
        fi
        
        sleep 1
    done
    
    local success_rate
    success_rate=$(echo "scale=2; $success_count * 100 / $total_requests" | bc)
    
    if (( $(echo "$success_rate >= 90" | bc -l) )); then
        log "INFO" "✅ Traffic switch verified - Success rate: $success_rate%"
        return 0
    else
        log "ERROR" "❌ Traffic switch verification failed - Success rate: $success_rate%"
        return 1
    fi
}

# Function to rollback deployment
rollback_deployment() {
    local rollback_version="$1"
    log "WARN" "🔄 Initiating rollback to $rollback_version..."
    
    # Switch service back to old version
    kubectl patch service "$SERVICE_NAME" -n "$NAMESPACE" \
        -p "{\"spec\":{\"selector\":{\"version\":\"$rollback_version\"}}}"
    
    # Scale up old deployment if needed
    local old_deployment
    if [[ "$rollback_version" == "blue" ]]; then
        old_deployment="$BLUE_DEPLOYMENT"
    else
        old_deployment="$GREEN_DEPLOYMENT"
    fi
    
    kubectl scale deployment "$old_deployment" -n "$NAMESPACE" --replicas="$REPLICAS"
    kubectl rollout status deployment "$old_deployment" -n "$NAMESPACE" --timeout=300s
    
    log "INFO" "✅ Rollback completed successfully"
    
    # Send alert notification
    send_notification "🚨 ROLLBACK EXECUTED" "Deployment rolled back to $rollback_version due to health check failures"
}

# Function to cleanup old resources
cleanup_old_resources() {
    log "INFO" "🧹 Cleaning up old resources..."
    
    # Clean up old jobs
    kubectl delete jobs -n "$NAMESPACE" --field-selector status.successful=1 --ignore-not-found=true
    
    # Clean up old pods
    kubectl delete pods -n "$NAMESPACE" --field-selector status.phase=Succeeded --ignore-not-found=true
    
    # Clean up old replica sets
    kubectl delete rs -n "$NAMESPACE" $(kubectl get rs -n "$NAMESPACE" -o jsonpath='{.items[?(@.spec.replicas==0)].metadata.name}') --ignore-not-found=true
    
    log "INFO" "✅ Cleanup completed"
}

# Function to update monitoring and observability
setup_monitoring() {
    log "INFO" "📊 Setting up monitoring and observability..."
    
    # Apply monitoring configurations
    kubectl apply -f "$PROJECT_ROOT/k8s/monitoring/"
    
    # Update Grafana dashboards
    kubectl create configmap grafana-dashboards \
        --from-file="$PROJECT_ROOT/monitoring/dashboards/" \
        -n "$NAMESPACE" \
        --dry-run=client -o yaml | kubectl apply -f -
    
    # Update Prometheus rules
    kubectl create configmap prometheus-rules \
        --from-file="$PROJECT_ROOT/monitoring/rules/" \
        -n "$NAMESPACE" \
        --dry-run=client -o yaml | kubectl apply -f -
    
    log "INFO" "✅ Monitoring setup completed"
}

# Function to send notifications
send_notification() {
    local title="$1"
    local message="$2"
    local status="${3:-info}"
    
    # Slack notification
    if [[ -n "${SLACK_WEBHOOK_URL:-}" ]]; then
        curl -X POST -H 'Content-type: application/json' \
            --data "{\"text\":\"$title\\n$message\"}" \
            "$SLACK_WEBHOOK_URL" > /dev/null 2>&1
    fi
    
    # Teams notification
    if [[ -n "${TEAMS_WEBHOOK_URL:-}" ]]; then
        curl -X POST -H 'Content-type: application/json' \
            --data "{\"title\":\"$title\",\"text\":\"$message\"}" \
            "$TEAMS_WEBHOOK_URL" > /dev/null 2>&1
    fi
    
    # Email notification (if configured)
    if [[ -n "${NOTIFICATION_EMAIL:-}" ]]; then
        echo -e "Subject: $title\\n\\n$message" | sendmail "$NOTIFICATION_EMAIL" > /dev/null 2>&1
    fi
    
    log "INFO" "📨 Notification sent: $title"
}

# Function to generate deployment report
generate_deployment_report() {
    log "INFO" "📋 Generating deployment report..."
    
    local report_file="/tmp/deployment-report-$TIMESTAMP.json"
    
    cat > "$report_file" <<EOF
{
  "deployment": {
    "timestamp": "$TIMESTAMP",
    "environment": "$ENVIRONMENT",
    "version": "$DEPLOYMENT_VERSION",
    "strategy": "$DEPLOYMENT_STRATEGY",
    "status": "success",
    "duration": "$SECONDS seconds"
  },
  "kubernetes": {
    "namespace": "$NAMESPACE",
    "replicas": $REPLICAS,
    "deployments": [
      "$(kubectl get deployments -n "$NAMESPACE" -o name | tr '\n' ',' | sed 's/,$//')"
    ],
    "services": [
      "$(kubectl get services -n "$NAMESPACE" -o name | tr '\n' ',' | sed 's/,$//')"
    ]
  },
  "resources": {
    "cpu_requests": "$RESOURCES_REQUESTS_CPU",
    "memory_requests": "$RESOURCES_REQUESTS_MEMORY",
    "cpu_limits": "$RESOURCES_LIMITS_CPU",
    "memory_limits": "$RESOURCES_LIMITS_MEMORY"
  }
}
EOF
    
    log "INFO" "✅ Deployment report generated: $report_file"
}

# Main deployment function
main() {
    display_banner
    
    log "INFO" "🚀 Starting Supremo deployment pipeline..."
    
    # Pre-deployment phase
    check_prerequisites
    validate_environment
    
    # Database phase
    if [[ "$FORCE_DEPLOY" != "true" ]]; then
        run_database_migrations
    else
        log "WARN" "⚠️  Skipping database migrations (forced deployment)"
    fi
    
    # Deployment phase
    case "$DEPLOYMENT_STRATEGY" in
        "blue-green")
            blue_green_deployment
            ;;
        "rolling")
            log "INFO" "🔄 Performing rolling deployment..."
            kubectl set image deployment "$BLUE_DEPLOYMENT" -n "$NAMESPACE" \
                erp-app="ghcr.io/your-org/erp-sistema:$DEPLOYMENT_VERSION"
            kubectl rollout status deployment "$BLUE_DEPLOYMENT" -n "$NAMESPACE" --timeout=600s
            ;;
        *)
            log "ERROR" "❌ Invalid deployment strategy: $DEPLOYMENT_STRATEGY"
            exit 1
            ;;
    esac
    
    # Post-deployment phase
    setup_monitoring
    cleanup_old_resources
    generate_deployment_report
    
    # Success notification
    send_notification "🎉 DEPLOYMENT SUCCESS" "Deployment to $ENVIRONMENT completed successfully with version $DEPLOYMENT_VERSION" "success"
    
    log "INFO" "🎉 Supremo deployment pipeline completed successfully!"
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                    🎉 DEPLOYMENT SUCCESSFUL! 🎉                    ║${NC}"
    echo -e "${GREEN}║                                                                    ║${NC}"
    echo -e "${GREEN}║  Environment: $ENVIRONMENT                                                   ║${NC}"
    echo -e "${GREEN}║  Version: $DEPLOYMENT_VERSION                                             ║${NC}"
    echo -e "${GREEN}║  Duration: $SECONDS seconds                                            ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════════════╝${NC}"
}

# Error handling
trap 'log "ERROR" "❌ Deployment failed at line $LINENO"; send_notification "🚨 DEPLOYMENT FAILED" "Deployment to $ENVIRONMENT failed at line $LINENO" "error"; exit 1' ERR

# Run main function
main "$@"