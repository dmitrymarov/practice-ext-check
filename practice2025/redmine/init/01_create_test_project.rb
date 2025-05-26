#!/usr/bin/env ruby
Rails.application.eager_load!

puts "=== Redmine Initialization for MediaWiki Support System ==="

begin
  # Create API user
  api_user = User.find_or_create_by(login: 'mediawiki_api') do |user|
    user.firstname = 'MediaWiki'
    user.lastname = 'API'
    user.mail = 'noreply@localhost'
    user.password = 'mediawiki_api_password'
    user.password_confirmation = 'mediawiki_api_password'
    user.admin = false
    user.status = User::STATUS_ACTIVE
  end

  if api_user.persisted?
    api_key = Token.find_or_create_by(user: api_user, action: 'api') do |token|
      token.value = Redmine::Utils.random_hex(40)
    end
    
    puts "✓ API User created: #{api_user.login}"
    puts "✓ API Key: #{api_key.value}"
  end

  # Create Support Project
  support_project = Project.find_or_create_by(identifier: 'support-system') do |project|
    project.name = 'Support System'
    project.description = 'Project for MediaWiki Support System integration'
    project.is_public = true
    project.status = Project::STATUS_ACTIVE
  end

  if support_project.persisted?
    puts "✓ Support project created: #{support_project.name}"

    # Enable modules
    enabled_modules = %w[issue_tracking time_tracking wiki files boards]
    enabled_modules << 'helpdesk' if Redmine::Plugin.installed?(:redmine_helpdesk)
    
    support_project.enabled_module_names = enabled_modules
    support_project.save!

    # Create trackers
    support_tracker = Tracker.find_or_create_by(name: 'Support Request') do |tracker|
      tracker.default_status = IssueStatus.find_by(name: 'New') || IssueStatus.first
      tracker.is_in_chlog = true
      tracker.position = 1
    end

    incident_tracker = Tracker.find_or_create_by(name: 'Incident') do |tracker|
      tracker.default_status = IssueStatus.find_by(name: 'New') || IssueStatus.first
      tracker.is_in_chlog = true
      tracker.position = 2
    end

    support_project.trackers = [support_tracker, incident_tracker]
    support_project.save!

    # Add API user to project
    member_role = Role.find_by(name: 'Manager') || Role.first
    if member_role
      Member.find_or_create_by(project: support_project, user: api_user) do |member|
        member.roles = [member_role]
      end
    end

    puts "✓ Project configuration complete"
  end

  # Configure system settings
  Setting.rest_api_enabled = 1
  Setting.jsonp_enabled = 1
  Setting.app_title = 'Support System - Redmine'
  Setting.mail_from = 'support@localhost' unless Setting.mail_from.present?

  puts "✓ System settings configured"
  puts "\nAPI Key for MediaWiki: #{Token.find_by(user: api_user, action: 'api')&.value}"

rescue => e
  puts "✗ Error during initialization: #{e.message}"
  exit 1
end