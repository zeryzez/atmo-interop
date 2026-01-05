<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="html" encoding="UTF-8" indent="yes"/>
    
    <xsl:template match="/">
        <div class="meteo-container">
            <h2>🌤️ Météo du jour</h2>
            <div class="meteo-summary">
                <xsl:apply-templates select="//prevision[@periode='matin']"/>
                <xsl:apply-templates select="//prevision[@periode='apres-midi']"/>
                <xsl:apply-templates select="//prevision[@periode='soir']"/>
            </div>
            
            <div class="meteo-alerts">
                <xsl:call-template name="check-weather-conditions"/>
            </div>
        </div>
    </xsl:template>
    
    <xsl:template match="prevision">
        <div class="meteo-period">
            <h3>
                <xsl:choose>
                    <xsl:when test="@periode='matin'">🌅 Matin</xsl:when>
                    <xsl:when test="@periode='apres-midi'">☀️ Après-midi</xsl:when>
                    <xsl:when test="@periode='soir'">🌙 Soir</xsl:when>
                </xsl:choose>
            </h3>
            
            <div class="meteo-details">
                <!-- Température -->
                <xsl:if test="temperature">
                    <div class="meteo-item">
                        <span class="icon">🌡️</span>
                        <span class="value">
                            <xsl:value-of select="temperature/@min"/>°C - 
                            <xsl:value-of select="temperature/@max"/>°C
                        </span>
                    </div>
                </xsl:if>
                
                <!-- Précipitations -->
                <xsl:if test="precipitation">
                    <div class="meteo-item">
                        <xsl:choose>
                            <xsl:when test="contains(precipitation/@type, 'pluie')">
                                <span class="icon">🌧️</span>
                            </xsl:when>
                            <xsl:when test="contains(precipitation/@type, 'neige')">
                                <span class="icon">❄️</span>
                            </xsl:when>
                            <xsl:otherwise>
                                <span class="icon">💧</span>
                            </xsl:otherwise>
                        </xsl:choose>
                        <span class="value">
                            <xsl:value-of select="precipitation/@probabilite"/>%
                        </span>
                    </div>
                </xsl:if>
                
                <!-- Vent -->
                <xsl:if test="vent">
                    <div class="meteo-item">
                        <xsl:choose>
                            <xsl:when test="vent/@force &gt; 60">
                                <span class="icon">🌪️</span>
                            </xsl:when>
                            <xsl:when test="vent/@force &gt; 40">
                                <span class="icon">💨</span>
                            </xsl:when>
                            <xsl:otherwise>
                                <span class="icon">🍃</span>
                            </xsl:otherwise>
                        </xsl:choose>
                        <span class="value">
                            <xsl:value-of select="vent/@force"/> km/h 
                            <xsl:value-of select="vent/@direction"/>
                        </span>
                    </div>
                </xsl:if>
                
                <!-- Conditions générales -->
                <xsl:if test="conditions">
                    <div class="meteo-item">
                        <span class="conditions">
                            <xsl:value-of select="conditions"/>
                        </span>
                    </div>
                </xsl:if>
            </div>
        </div>
    </xsl:template>
    
    <xsl:template name="check-weather-conditions">
        <xsl:variable name="max-temp" select="//temperature/@max"/>
        <xsl:variable name="min-temp" select="//temperature/@min"/>
        <xsl:variable name="max-wind" select="//vent/@force"/>
        <xsl:variable name="max-precip" select="//precipitation/@probabilite"/>
        
        <h3>⚠️ Conditions particulières</h3>
        <ul class="weather-warnings">
            <xsl:if test="$min-temp &lt; 0">
                <li class="warning cold">❄️ Températures négatives - Risque de verglas</li>
            </xsl:if>
            
            <xsl:if test="$min-temp &lt; 5 and $min-temp &gt;= 0">
                <li class="warning cold">🥶 Il va faire froid</li>
            </xsl:if>
            
            <xsl:if test="$max-precip &gt; 70">
                <li class="warning rain">☔ Fortes probabilités de précipitations</li>
            </xsl:if>
            
            <xsl:if test="$max-precip &gt; 40 and $max-precip &lt;= 70">
                <li class="warning rain">🌂 Risque de précipitations</li>
            </xsl:if>
            
            <xsl:if test="$max-wind &gt; 60">
                <li class="warning wind">🌪️ Vent fort - Prudence sur la route</li>
            </xsl:if>
            
            <xsl:if test="$max-wind &gt; 40 and $max-wind &lt;= 60">
                <li class="warning wind">💨 Vent modéré</li>
            </xsl:if>
            
            <xsl:if test="$max-temp &gt; 30">
                <li class="warning hot">🥵 Forte chaleur</li>
            </xsl:if>
            
            <xsl:if test="$max-precip &lt; 20 and $max-wind &lt; 30 and $min-temp &gt; 10 and $max-temp &lt; 25">
                <li class="good">✅ Conditions idéales pour prendre la voiture</li>
            </xsl:if>
        </ul>
    </xsl:template>
    
</xsl:stylesheet>
