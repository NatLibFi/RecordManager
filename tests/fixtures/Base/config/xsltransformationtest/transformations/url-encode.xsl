<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:php="http://php.net/xsl">
  <xsl:output method="xml" indent="no"/>
 
  <xsl:template name="urlencode">
    <xsl:param name="url"/>

    <!-- Some URLs which should already be escaped in the metadata still contain these characters -->
    <xsl:choose>

      <xsl:when test="contains($url, '[')">
        <xsl:call-template name="urlencode">
          <xsl:with-param name="url" select="php:function('str_replace', '[', '%5B', string($url))"/>
        </xsl:call-template>
      </xsl:when>

      <xsl:when test="contains($url, ']')">
        <xsl:call-template name="urlencode">
          <xsl:with-param name="url" select="php:function('str_replace', ']', '%5D', string($url))"/>
        </xsl:call-template>
      </xsl:when>

      <xsl:when test="contains($url, ' ')">
        <xsl:call-template name="urlencode">
          <xsl:with-param name="url" select="php:function('str_replace', ' ', '%20', string($url))"/>
        </xsl:call-template>
      </xsl:when>

      <xsl:when test="contains($url, '(')">
        <xsl:call-template name="urlencode">
          <xsl:with-param name="url" select="php:function('str_replace', '(', '%28', string($url))"/>
        </xsl:call-template>
      </xsl:when>

      <xsl:when test="contains($url, ')')">
        <xsl:call-template name="urlencode">
          <xsl:with-param name="url" select="php:function('str_replace', ')', '%29', string($url))"/>
        </xsl:call-template>
      </xsl:when>

      <xsl:otherwise>
        <xsl:value-of select="$url"/>
      </xsl:otherwise>

    </xsl:choose>

  </xsl:template>

</xsl:stylesheet>
