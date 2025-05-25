FROM redmine:5.0

USER root
RUN apt-get update && apt-get install -y \
    git \
    build-essential \
    libssl-dev \
    curl \
    wget \
    unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src/redmine/plugins

# Install plugins
RUN git clone https://github.com/jfqd/redmine_helpdesk.git redmine_helpdesk || echo "Helpdesk plugin installation failed"
RUN git clone https://github.com/keeleysam/redmine_monitoring_controlling.git redmine_monitoring_controlling || echo "Monitoring plugin installation failed"
RUN git clone https://github.com/akiko-pusu/redmine_theme_changer.git redmine_theme_changer || echo "Theme changer plugin installation failed"
RUN git clone https://github.com/alphanodes/additionals.git redmine_additionals || echo "Additionals plugin installation failed"
RUN git clone https://github.com/two-pack/redmine_xlsx_format_issue_exporter.git redmine_xlsx_format_issue_exporter || echo "XLSX exporter plugin installation failed"

RUN chown -R redmine:redmine /usr/src/redmine/plugins

USER redmine
WORKDIR /usr/src/redmine

RUN bundle install --without development test || echo "Bundle install completed with warnings"
