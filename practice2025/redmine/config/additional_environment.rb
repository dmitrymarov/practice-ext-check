# Configuration for Redmine plugins and custom settings

# Enable API access
RedmineApp::Application.configure do
  config.force_ssl = false
  config.consider_all_requests_local = false
end

# HelpDesk configuration
Rails.application.config.after_initialize do
  if defined?(RedmineHelpdesk)
    RedmineHelpdesk::MailHandler.logger = Rails.logger
  end
end

# API settings with CORS
Rails.application.config.middleware.insert_before 0, Rack::Cors do
  allow do
    origins '*'
    resource '/issues.json', 
      headers: :any, 
      methods: [:get, :post, :put, :patch, :delete, :options],
      credentials: false
    resource '/uploads.json',
      headers: :any,
      methods: [:post, :options],
      credentials: false
    resource '/projects.json',
      headers: :any,
      methods: [:get, :post, :options],
      credentials: false
  end
end

# Configure issue priorities
Rails.application.config.after_initialize do
  if ActiveRecord::Base.connection.table_exists?('enumerations')
    begin
      critical = IssuePriority.find_or_create_by(name: 'Critical') do |priority|
        priority.position = 5
        priority.is_default = false
      end
      
      high = IssuePriority.find_or_create_by(name: 'High') do |priority|
        priority.position = 4
        priority.is_default = false
      end
      
      normal = IssuePriority.find_or_create_by(name: 'Normal') do |priority|
        priority.position = 3
        priority.is_default = true
      end
      
      low = IssuePriority.find_or_create_by(name: 'Low') do |priority|
        priority.position = 2
        priority.is_default = false
      end
      
      Rails.logger.info "Issue priorities configured successfully"
    rescue => e
      Rails.logger.error "Error configuring issue priorities: #{e.message}"
    end
  end
end

# Configure email settings
ActionMailer::Base.smtp_settings = {
  address: ENV['SMTP_HOST'] || 'localhost',
  port: ENV['SMTP_PORT'] || 587,
  domain: ENV['SMTP_DOMAIN'] || 'localhost',
  user_name: ENV['SMTP_USER'],
  password: ENV['SMTP_PASSWORD'],
  authentication: ENV['SMTP_AUTH'] || 'plain',
  enable_starttls_auto: ENV['SMTP_TLS'] == 'true'
}
